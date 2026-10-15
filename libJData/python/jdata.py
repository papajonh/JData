"""
libJData - Parser e serializador do formato JData.
Python 3.10+

API:
    import jdata
    dados = jdata.parse(texto)
    texto = jdata.stringify(dados)
    dados = jdata.parse_file("arquivo.jdata")
    jdata.save_file("arquivo.jdata", dados)
"""
from __future__ import annotations

import json
import re
from pathlib import Path
from typing import Any


class JDataError(ValueError):
    """Erro de sintaxe ou estrutura JData."""


def _scalar(value: str) -> Any:
    value = value.strip()
    if value == "true":
        return True
    if value == "false":
        return False
    if value == "null":
        return None
    if re.fullmatch(r"-?(?:0|[1-9]\d*)", value):
        try:
            return int(value)
        except ValueError:
            pass
    if re.fullmatch(r"-?(?:\d+\.\d*|\.\d+)(?:[eE][+-]?\d+)?|-?\d+[eE][+-]?\d+", value):
        try:
            return float(value)
        except ValueError:
            pass
    # Permite strings entre aspas JSON para preservar espaços e tipos ambíguos.
    if value.startswith('"'):
        try:
            decoded = json.loads(value)
            if isinstance(decoded, str):
                return decoded
        except json.JSONDecodeError:
            pass
    return value


def parse(text: str) -> dict[str, dict[str, Any]]:
    """Converte texto JData em dicionário de sessões."""
    root: dict[str, dict[str, Any]] = {}
    session: str | None = None
    stack: list[dict[str, Any]] = []

    for line_no, raw in enumerate(text.splitlines(), start=1):
        line = raw.strip()
        if not line or line.startswith("#"):
            continue

        match = re.fullmatch(r"\[([^\]]+)\]", line)
        if match:
            session = match.group(1).strip()
            if not session:
                raise JDataError(f"Sessão vazia na linha {line_no}.")
            root.setdefault(session, {})
            stack = []
            continue

        if session is None:
            raise JDataError(f"Conteúdo fora de uma sessão na linha {line_no}.")

        match = re.fullmatch(r"\$([^\s]+)(?:\s+(.*))?", line)
        if match:
            if stack:
                raise JDataError(f"Propriedade $ fora do nível permitido na linha {line_no}.")
            root[session][match.group(1)] = _scalar(match.group(2) or "")
            continue

        # Lista nomeada: <nome>; sublista: *<nome>, **<nome>, ...
        match = re.fullmatch(r"(\**)<([^<>]+)>", line)
        if match:
            stars, name = len(match.group(1)), match.group(2).strip()
            if not name:
                raise JDataError(f"Nome de lista vazio na linha {line_no}.")
            if stars == 0:
                if stack:
                    raise JDataError(f"Lista de nível raiz dentro de outra lista na linha {line_no}.")
                root[session][name] = []
                stack.append({"level": 0, "path": [name]})
                continue

            parent_level = stars - 1
            while stack and stack[-1]["level"] > parent_level:
                stack.pop()
            if not stack or stack[-1]["level"] != parent_level:
                raise JDataError(f"Nível de lista inválido na linha {line_no}.")
            parent_path = stack[-1]["path"]
            child_path = parent_path + [name]
            parent = _at_path(root[session], parent_path)
            parent[name] = []
            stack.append({"level": stars, "path": child_path})
            continue

        # Elemento de lista: *chave valor ou *Valor
        match = re.fullmatch(r"(\*+)(?:\s*(.*))", line)
        if match:
            stars, payload = len(match.group(1)), match.group(2).strip()
            if not payload:
                # Elemento vazio não cria None e não muda o nível.
                continue
            expected = stars - 1
            while stack and stack[-1]["level"] > expected:
                stack.pop()
            if not stack or stack[-1]["level"] != expected:
                raise JDataError(f"Elemento de lista fora do nível esperado na linha {line_no}.")
            target = _at_path(root[session], stack[-1]["path"])
            item_match = re.fullmatch(r"([^\s]+)\s+(.+)", payload, flags=re.S)
            if item_match:
                target.append({item_match.group(1): _scalar(item_match.group(2))})
            else:
                target.append(_scalar(payload))
            continue

        raise JDataError(f"Sintaxe JData inválida na linha {line_no}: {line}")

    return root


def stringify(data: dict[str, dict[str, Any]]) -> str:
    """Converte dicionário de sessões em texto JData."""
    lines: list[str] = []
    if not isinstance(data, dict):
        raise TypeError("Os dados devem ser um dicionário de sessões.")
    for session, values in data.items():
        if not isinstance(session, str) or not session or not isinstance(values, dict):
            raise TypeError("Cada sessão precisa ter nome textual e conter um dicionário.")
        lines.append(f"[{session}]")
        _write_entries(values, lines, 0)
        lines.append("")
    return "\n".join(lines).rstrip() + "\n"


def parse_file(path: str | Path, encoding: str = "utf-8") -> dict[str, dict[str, Any]]:
    """Lê e interpreta um arquivo .jdata."""
    return parse(Path(path).read_text(encoding=encoding))


def save_file(path: str | Path, data: dict[str, dict[str, Any]], encoding: str = "utf-8") -> None:
    """Serializa e salva dados em um arquivo .jdata."""
    Path(path).write_text(stringify(data), encoding=encoding)


def _at_path(root: dict[str, Any], path: list[str]) -> dict[str, Any] | list[Any]:
    current: Any = root
    for part in path:
        if not isinstance(current, dict):
            raise JDataError(f"Caminho de lista inválido: {part}")
        current = current.setdefault(part, [])
    if not isinstance(current, (dict, list)):
        raise JDataError("O caminho não aponta para uma estrutura.")
    return current


def _encode(value: Any) -> str:
    if value is None:
        return "null"
    if value is True:
        return "true"
    if value is False:
        return "false"
    if isinstance(value, (int, float)):
        return str(value)
    if isinstance(value, str):
        if (
            value == ""
            or value != value.strip()
            or "\n" in value
            or "\r" in value
            or value in {"true", "false", "null"}
            or re.fullmatch(r"-?\d+(?:\.\d+)?", value)
        ):
            return json.dumps(value, ensure_ascii=False)
        return value
    raise TypeError(f"Tipo não suportado em JData: {type(value).__name__}")


def _write_entries(entries: dict[str, Any], lines: list[str], level: int) -> None:
    for key, value in entries.items():
        if not isinstance(key, str):
            raise TypeError("As chaves das propriedades precisam ser texto.")
        if isinstance(value, dict):
            stars = "*" * level
            lines.append(f"{stars}<{key}>")
            _write_entries(value, lines, level + 1)
        elif isinstance(value, list):
            stars = "*" * level
            lines.append(f"{stars}<{key}>")
            for item in value:
                prefix = "*" * (level + 1)
                if isinstance(item, dict):
                    for item_key, item_value in item.items():
                        if isinstance(item_value, (dict, list)):
                            nested_prefix = "*" * (level + 1)
                            lines.append(f"{nested_prefix}<{item_key}>")
                            if isinstance(item_value, dict):
                                _write_entries(item_value, lines, level + 2)
                            else:
                                for nested_item in item_value:
                                    lines.append("*" * (level + 2) + _encode(nested_item))
                        else:
                            lines.append(f"{prefix}{item_key} {_encode(item_value)}")
                else:
                    lines.append(prefix + _encode(item))
        else:
            if level == 0:
                lines.append(f"${key} {_encode(value)}")
            else:
                lines.append(f"{'*' * level}{key} {_encode(value)}")
