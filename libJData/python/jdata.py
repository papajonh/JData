import json
import re


class JDataError(Exception):
    def __init__(self, message, line=None):
        self.line = line
        super().__init__(f"{message} (linha {line})" if line else message)


def _parse_scalar(value):
    text = value.strip()
    if text == "":
        return ""
    if len(text) >= 2 and text[0] == '"' and text[-1] == '"':
        try:
            return json.loads(text)
        except Exception:
            return text[1:-1]
    if text == "true":
        return True
    if text == "false":
        return False
    if text == "null":
        return None
    if re.fullmatch(r"-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?", text):
        try:
            return int(text) if "." not in text and "e" not in text.lower() else float(text)
        except ValueError:
            pass
    return text


def _format_scalar(value):
    if isinstance(value, str):
        if (
            value == ""
            or re.fullmatch(r"(true|false|null)", value)
            or re.fullmatch(r"-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?", value)
            or value != value.strip()
            or value.startswith('"')
        ):
            return json.dumps(value, ensure_ascii=False)
        return value
    if value is None:
        return "null"
    if isinstance(value, bool):
        return "true" if value else "false"
    if isinstance(value, (int, float)):
        return str(value)
    raise TypeError("Valor não suportado como escalar.")


def _is_scalar_leaf(value):
    return value is None or isinstance(value, (str, int, float, bool))


def _is_leaf_array(array):
    return isinstance(array, list) and all(_is_scalar_leaf(v) for v in array)


def _is_numeric_name(name):
    return re.fullmatch(r"\d+", name) is not None


# ---------------------------------------------------------------
# PARSE
# ---------------------------------------------------------------
def parse(source):
    if not isinstance(source, str):
        raise TypeError("JData.parse espera uma string.")

    if source.startswith("\ufeff"):
        source = source[1:]
    lines = re.split(r"\r?\n", source)

    result = {}
    stack = []
    current_session = None
    session_mode = None  # "object" | "scalarArray" | "nestedArray"

    def fail(message, line_number):
        raise JDataError(message, line_number)

    def require_list(level, line_number):
        if level - 1 >= len(stack):
            fail(f"Não existe lista aberta no nível {level}.", line_number)
        return stack[level - 1]

    def ensure_session_mode(mode, line_number):
        nonlocal session_mode
        if session_mode is None:
            session_mode = mode
            result[current_session] = {} if mode == "object" else []
            return
        if session_mode != mode:
            if session_mode == "object" and mode == "scalarArray":
                if not isinstance(result[current_session].get("0"), list):
                    result[current_session]["0"] = []
                return
            fail(
                f'A sessão "{current_session}" já foi iniciada como {session_mode}; não pode virar {mode}.',
                line_number,
            )

    def push_anon_array(parent, line_number):
        if parent.get("isMultiKey"):
            key = str(len(parent["array"]))
            parent["array"][key] = []
            stack.append({
                "name": key,
                "array": parent["array"][key],
                "isMultiKey": False,
                "isAnonArray": True,
            })
        else:
            anon = []
            parent["array"].append(anon)
            stack.append({
                "name": None,
                "array": anon,
                "isMultiKey": False,
                "isAnonArray": True,
            })

    def resolve_pending(pending, line, line_number):
        is_key_value = re.match(r"^\*+[^\s*<>]+\s+", line) is not None
        is_child_list = re.match(r"^\*+<[^<>]+>$", line) is not None
        is_child_anon = re.match(r"^\*+<>$", line) is not None
        is_scalar = re.match(r"^\*+[^\s*<>]+$", line) is not None

        if is_key_value or is_child_list or is_child_anon:
            pending["holder"][pending["name"]] = {}
            del stack[pending["level"]:]
            stack.append({
                "name": pending["name"],
                "array": pending["holder"][pending["name"]],
                "isMultiKey": True,
            })
        elif is_scalar:
            pending["holder"][pending["name"]] = []
            del stack[pending["level"]:]
            stack.append({
                "name": pending["name"],
                "array": pending["holder"][pending["name"]],
                "isMultiKey": False,
            })
        else:
            fail("Não foi possível determinar o tipo da lista.", line_number)

    for i, raw_line in enumerate(lines):
        line_number = i + 1
        line = raw_line.strip()
        if not line or line.startswith("#"):
            continue

        # Sessão
        m = re.match(r"^\[([^\]]+)\]$", line)
        if m:
            name = m.group(1).strip()
            if not name:
                fail("O nome da sessão não pode ser vazio.", line_number)
            if name in result:
                fail(f'A sessão "{name}" já foi declarada.', line_number)
            current_session = name
            session_mode = None
            result[name] = None
            stack = []
            continue

        if current_session is None:
            fail("Declare uma sessão usando [Nome].", line_number)

        # <>
        if line == "<>":
            if session_mode is None:
                session_mode = "nestedArray"
                result[current_session] = []
            if session_mode != "nestedArray":
                fail(
                    f'A sessão "{current_session}" não é uma lista de listas.',
                    line_number,
                )
            anon = []
            result[current_session].append(anon)
            stack = [{
                "name": None,
                "array": anon,
                "isMultiKey": False,
                "isAnonArray": True,
            }]
            continue

        # $chave valor
        if line.startswith("$"):
            m = re.match(r"^\$([^\s]+)(?:\s+([\s\S]*))?$", line)
            if not m:
                fail("Linha $ inválida.", line_number)
            ensure_session_mode("object", line_number)
            result[current_session][m.group(1)] = _parse_scalar(m.group(2) or "")
            stack = []
            continue

        # <nome>
        m = re.match(r"^<([^<>]+)>$", line)
        if m:
            name = m.group(1).strip()
            if not name:
                fail("O nome da lista não pode ser vazio.", line_number)

            if _is_numeric_name(name):
                if session_mode is None:
                    session_mode = "nestedArray"
                    result[current_session] = []
                if not isinstance(result[current_session], list):
                    fail(
                        f'A sessão "{current_session}" já foi iniciada como objeto; não pode conter <{name}> anônimo.',
                        line_number,
                    )
                anon = []
                result[current_session].append(anon)
                stack = [{
                    "name": None,
                    "array": anon,
                    "isMultiKey": False,
                    "isAnonArray": True,
                }]
                continue

            ensure_session_mode("object", line_number)
            result[current_session][name] = None
            stack = [{
                "name": name,
                "array": None,
                "isMultiKey": False,
                "pending": True,
                "holder": result[current_session],
                "level": 0,
            }]
            continue

        # *...
        m = re.match(r"^(\*+)(.*)$", line)
        if not m:
            fail("Sintaxe inválida. Use $, <lista> ou *elemento.", line_number)

        level = len(m.group(1))
        content = m.group(2).strip()
        if not content:
            fail("Elemento vazio não é permitido.", line_number)

        # Resolve pendência
        if stack and stack[-1].get("pending"):
            top = stack[-1]
            if top["level"] + 1 == level:
                resolve_pending(top, line, line_number)

        # *<nome> / *<>
        cm = re.match(r"^<([^<>]*)>$", content)
        if cm:
            parent = require_list(level, line_number)
            child_name = cm.group(1).strip()
            del stack[level:]

            if child_name == "":
                push_anon_array(parent, line_number)
            elif _is_numeric_name(child_name) and not parent.get("isMultiKey"):
                anon = []
                parent["array"].append(anon)
                stack.append({
                    "name": None,
                    "array": anon,
                    "isMultiKey": False,
                    "isAnonArray": True,
                })
            elif _is_numeric_name(child_name) and parent.get("isMultiKey"):
                parent["array"][child_name] = []
                stack.append({
                    "name": child_name,
                    "array": parent["array"][child_name],
                    "isMultiKey": False,
                })
            else:
                if parent.get("isMultiKey"):
                    parent["array"][child_name] = None
                    stack.append({
                        "name": child_name,
                        "array": None,
                        "isMultiKey": False,
                        "pending": True,
                        "holder": parent["array"],
                        "level": level,
                    })
                else:
                    child_object = {child_name: None}
                    parent["array"].append(child_object)
                    stack.append({
                        "name": child_name,
                        "array": None,
                        "isMultiKey": False,
                        "pending": True,
                        "holder": child_object,
                        "level": level,
                    })
            continue

        # *solto sem lista aberta
        if not stack:
            if session_mode == "object":
                if not isinstance(result[current_session].get("0"), list):
                    result[current_session]["0"] = []
                result[current_session]["0"].append(_parse_scalar(content))
            else:
                ensure_session_mode("scalarArray", line_number)
                result[current_session].append(_parse_scalar(content))
            continue

        current_list = require_list(level, line_number)
        del stack[level:]

        pm = re.match(r"^([^\s]+)(?:\s+([\s\S]*))?$", content)
        if not pm:
            fail("Elemento inválido.", line_number)

        key = pm.group(1)
        has_value = pm.group(2) is not None

        if current_list.get("isMultiKey"):
            current_list["array"][key] = _parse_scalar(pm.group(2)) if has_value else None
        elif not has_value:
            current_list["array"].append(_parse_scalar(key))
        else:
            current_list["array"].append({key: _parse_scalar(pm.group(2))})

    if current_session is None:
        raise JDataError("Nenhuma sessão foi declarada.")

    for k in list(result.keys()):
        if result[k] is None:
            result[k] = {}

    return result


# ---------------------------------------------------------------
# STRINGIFY
# ---------------------------------------------------------------
def stringify(inp):
    if not isinstance(inp, dict):
        raise TypeError("JData.stringify espera um objeto (dict).")

    keys = list(inp.keys())
    if not keys:
        raise TypeError("O objeto raiz está vazio.")

    lines = []

    def write_list_contents(array, level):
        for idx, item in enumerate(array):
            stars = "*" * level

            if _is_scalar_leaf(item):
                lines.append(stars + _format_scalar(item))
                continue

            if isinstance(item, list):
                lines.append("*" * (level - 1) + f"<{idx}>")
                write_list_contents(item, level)
                continue

            # objeto (dict)
            if isinstance(item, dict):
                item_keys = list(item.keys())
                if len(item_keys) == 1:
                    key = item_keys[0]
                    value = item[key]
                    if isinstance(value, list):
                        lines.append(stars + f"<{key}>")
                        write_list_contents(value, level + 1)
                    elif isinstance(value, dict):
                        lines.append(stars + f"<{key}>")
                        write_object_as_multi_key(value, level + 1)
                    else:
                        lines.append(stars + f"{key} {_format_scalar(value)}")
                else:
                    lines.append(stars + "<>")
                    write_object_as_multi_key(item, level + 1)

    def write_object_as_multi_key(obj, level):
        stars = "*" * level
        for key, value in obj.items():
            if isinstance(value, list):
                lines.append(stars + f"<{key}>")
                write_list_contents(value, level + 1)
            elif isinstance(value, dict):
                lines.append(stars + f"<{key}>")
                write_object_as_multi_key(value, level + 1)
            else:
                lines.append(stars + f"{key} {_format_scalar(value)}")

    def write_session(name, data):
        lines.append(f"[{name}]")

        if isinstance(data, list):
            if _is_leaf_array(data):
                for v in data:
                    lines.append("*" + _format_scalar(v))
            else:
                for idx