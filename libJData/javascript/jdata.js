(function (root, factory) {
    const JData = factory();

    if (typeof module !== "undefined" && module.exports) {
        module.exports = JData;
    }

    if (root) {
        root.JData = JData;
    }
})(typeof globalThis !== "undefined" ? globalThis : this, function () {
    "use strict";

    class JDataError extends Error {
        constructor(message, line) {
            super(line ? `${message} (linha ${line})` : message);
            this.name = "JDataError";
            this.line = line || null;
        }
    }

    function parseScalar(value) {
        const text = value.trim();
        if (text === "") return "";
        if (text.startsWith('"') && text.endsWith('"')) {
            try { return JSON.parse(text); } catch { return text.slice(1, -1); }
        }
        if (text === "true") return true;
        if (text === "false") return false;
        if (text === "null") return null;
        if (/^-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?$/.test(text)) {
            const n = Number(text);
            if (Number.isFinite(n)) return n;
        }
        return text;
    }

    function formatScalar(value) {
        if (typeof value === "string") {
            if (
                value === "" ||
                /^(true|false|null)$/.test(value) ||
                /^-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?$/.test(value) ||
                value !== value.trim() ||
                value.startsWith('"')
            ) {
                return JSON.stringify(value);
            }
            return value;
        }
        if (value === null || typeof value === "number" || typeof value === "boolean") {
            return String(value);
        }
        throw new TypeError("Valor não suportado como escalar.");
    }

    function isScalarLeaf(value) {
        return (
            value === null ||
            typeof value === "number" ||
            typeof value === "boolean" ||
            typeof value === "string"
        );
    }

    function isLeafArray(array) {
        return array.every(isScalarLeaf);
    }

    function isNumericName(name) {
        return /^\d+$/.test(name);
    }

    // ---------------------------------------------------------------
    // PARSE
    // ---------------------------------------------------------------
    function parse(source) {
        if (typeof source !== "string") {
            throw new TypeError("JData.parse espera uma string.");
        }

        const lines = source.replace(/^\uFEFF/, "").split(/\r?\n/);
        const result = {};
        const stack = [];
        let currentSession = null;
        let sessionMode = null;

        function fail(message, lineNumber) {
            throw new JDataError(message, lineNumber);
        }

        function requireList(level, lineNumber) {
            const entry = stack[level - 1];
            if (!entry) {
                fail(`Não existe lista aberta no nível ${level}.`, lineNumber);
            }
            return entry;
        }

        function ensureSessionMode(mode, lineNumber) {
            if (sessionMode === null) {
                sessionMode = mode;
                result[currentSession] = mode === "object" ? {} : [];
                return;
            }
            if (sessionMode !== mode) {
                if (sessionMode === "object" && mode === "scalarArray") {
                    if (!Array.isArray(result[currentSession]["0"])) {
                        result[currentSession]["0"] = [];
                    }
                    return;
                }
                fail(
                    `A sessão "${currentSession}" já foi iniciada como ${sessionMode}; não pode virar ${mode}.`,
                    lineNumber
                );
            }
        }

        function pushAnonArray(parent, lineNumber) {
            if (parent.isMultiKey) {
                const key = String(Object.keys(parent.array).length);
                parent.array[key] = [];
                stack.push({
                    name: key,
                    array: parent.array[key],
                    isMultiKey: false,
                    isAnonArray: true
                });
            } else {
                const anon = [];
                parent.array.push(anon);
                stack.push({
                    name: null,
                    array: anon,
                    isMultiKey: false,
                    isAnonArray: true
                });
            }
        }

        function resolvePending(pending, line, lineNumber) {
            const isKeyValue = /^\*+[^\s*<>]+\s+/.test(line);
            const isChildList = /^\*+<[^<>]+>$/.test(line);
            const isChildAnon = /^\*+<>$/.test(line);
            const isScalar = /^\*+[^\s*<>]+$/.test(line);

            if (isKeyValue || isChildList || isChildAnon) {
                pending.holder[pending.name] = {};
                stack.length = pending.level;
                stack.push({
                    name: pending.name,
                    array: pending.holder[pending.name],
                    isMultiKey: true
                });
            } else if (isScalar) {
                pending.holder[pending.name] = [];
                stack.length = pending.level;
                stack.push({
                    name: pending.name,
                    array: pending.holder[pending.name],
                    isMultiKey: false
                });
            } else {
                fail("Não foi possível determinar o tipo da lista.", lineNumber);
            }
        }

        for (let i = 0; i < lines.length; i++) {
            const lineNumber = i + 1;
            const line = lines[i].trim();
            if (!line || line.startsWith("#")) continue;

            // Sessão: [Nome]
            const sessionMatch = line.match(/^\[([^\]]+)\]$/);
            if (sessionMatch) {
                const name = sessionMatch[1].trim();
                if (!name) fail("O nome da sessão não pode ser vazio.", lineNumber);
                if (Object.prototype.hasOwnProperty.call(result, name)) {
                    fail(`A sessão "${name}" já foi declarada.`, lineNumber);
                }
                currentSession = name;
                sessionMode = null;
                result[name] = null;
                stack.length = 0;
                continue;
            }

            if (currentSession === null) {
                fail("Declare uma sessão usando [Nome].", lineNumber);
            }

            // Lista anônima na sessão: <>
            if (line === "<>") {
                if (sessionMode === null) {
                    sessionMode = "nestedArray";
                    result[currentSession] = [];
                }
                if (sessionMode !== "nestedArray") {
                    fail(
                        `A sessão "${currentSession}" não é uma lista de listas.`,
                        lineNumber
                    );
                }
                const anon = [];
                result[currentSession].push(anon);
                stack.length = 0;
                stack.push({
                    name: null,
                    array: anon,
                    isMultiKey: false,
                    isAnonArray: true
                });
                continue;
            }

            // Propriedade: $chave valor
            if (line.startsWith("$")) {
                const match = line.match(/^\$([^\s]+)(?:\s+([\s\S]*))?$/);
                if (!match) fail("Linha $ inválida.", lineNumber);

                ensureSessionMode("object", lineNumber);
                result[currentSession][match[1]] = parseScalar(match[2] ?? "");
                stack.length = 0;
                continue;
            }

            // Lista nomeada no topo da sessão: <nome>
            const rootListMatch = line.match(/^<([^<>]+)>$/);
            if (rootListMatch) {
                const name = rootListMatch[1].trim();
                if (!name) fail("O nome da lista não pode ser vazio.", lineNumber);

                if (isNumericName(name)) {
                    // <N> na sessão → array anônimo: sessão precisa ser array
                    if (sessionMode === null) {
                        sessionMode = "nestedArray";
                        result[currentSession] = [];
                    }
                    if (!Array.isArray(result[currentSession])) {
                        fail(
                            `A sessão "${currentSession}" já foi iniciada como objeto; não pode conter <${name}> anônimo.`,
                            lineNumber
                        );
                    }
                    const anon = [];
                    result[currentSession].push(anon);
                    stack.length = 0;
                    stack.push({
                        name: null,
                        array: anon,
                        isMultiKey: false,
                        isAnonArray: true
                    });
                    continue;
                }

                // nome não numérico → lista nomeada (objeto)
                ensureSessionMode("object", lineNumber);
                result[currentSession][name] = null;
                stack.length = 0;
                stack.push({
                    name,
                    array: null,
                    isMultiKey: false,
                    pending: true,
                    holder: result[currentSession],
                    level: 0
                });
                continue;
            }

            // Elementos e listas filhas
            const itemMatch = line.match(/^(\*+)(.*)$/);
            if (!itemMatch) {
                fail("Sintaxe inválida. Use $, <lista> ou *elemento.", lineNumber);
            }

            const level = itemMatch[1].length;
            const content = itemMatch[2].trim();
            if (!content) fail("Elemento vazio não é permitido.", lineNumber);

            if (stack.length > 0 && stack[stack.length - 1].pending) {
                const top = stack[stack.length - 1];
                if (top.level + 1 === level) {
                    resolvePending(top, line, lineNumber);
                }
            }

            const childMatch = content.match(/^<([^<>]*)>$/);
            if (childMatch) {
                const parent = requireList(level, lineNumber);
                const childName = childMatch[1].trim();
                stack.length = level;

                if (!childName) {
                    pushAnonArray(parent, lineNumber);
                } else if (isNumericName(childName) && !parent.isMultiKey) {
                    const anon = [];
                    parent.array.push(anon);
                    stack.push({
                        name: null,
                        array: anon,
                        isMultiKey: false,
                        isAnonArray: true
                    });
                } else if (isNumericName(childName) && parent.isMultiKey) {
                    parent.array[childName] = [];
                    stack.push({
                        name: childName,
                        array: parent.array[childName],
                        isMultiKey: false
                    });
                } else {
                    if (parent.isMultiKey) {
                        parent.array[childName] = null;
                        stack.push({
                            name: childName,
                            array: null,
                            isMultiKey: false,
                            pending: true,
                            holder: parent.array,
                            level
                        });
                    } else {
                        const childObject = { [childName]: null };
                        parent.array.push(childObject);
                        stack.push({
                            name: childName,
                            array: null,
                            isMultiKey: false,
                            pending: true,
                            holder: childObject,
                            level
                        });
                    }
                }
                continue;
            }

            // Sem lista aberta: *solto
            if (stack.length === 0) {
                if (sessionMode === "object") {
                    if (!Array.isArray(result[currentSession]["0"])) {
                        result[currentSession]["0"] = [];
                    }
                    result[currentSession]["0"].push(parseScalar(content));
                } else {
                    ensureSessionMode("scalarArray", lineNumber);
                    result[currentSession].push(parseScalar(content));
                }
                continue;
            }

            const currentList = requireList(level, lineNumber);
            stack.length = level;

            const propertyMatch = content.match(/^([^\s]+)(?:\s+([\s\S]*))?$/);
            if (!propertyMatch) fail("Elemento inválido.", lineNumber);

            const key = propertyMatch[1];
            const hasValue = propertyMatch[2] !== undefined;

            if (currentList.isMultiKey) {
                currentList.array[key] = hasValue
                    ? parseScalar(propertyMatch[2])
                    : null;
            } else if (!hasValue) {
                currentList.array.push(parseScalar(key));
            } else {
                currentList.array.push({
                    [key]: parseScalar(propertyMatch[2])
                });
            }
        }

        if (currentSession === null) {
            throw new JDataError("Nenhuma sessão foi declarada.");
        }

        for (const k of Object.keys(result)) {
            if (result[k] === null) result[k] = {};
        }

        return result;
    }

    // ---------------------------------------------------------------
    // STRINGIFY
    // ---------------------------------------------------------------
    function stringify(input, options = {}) {
        if (
            input === null ||
            typeof input !== "object" ||
            Array.isArray(input)
        ) {
            throw new TypeError("JData.stringify espera um objeto.");
        }

        const keys = Object.keys(input);
        if (keys.length === 0) {
            throw new TypeError("O objeto raiz está vazio.");
        }

        const lines = [];

        function writeScalarProperty(key, value) {
            lines.push(`$${key} ${formatScalar(value)}`);
        }

        function writeListContents(array, level) {
            for (let idx = 0; idx < array.length; idx++) {
                const item = array[idx];
                const stars = "*".repeat(level);

                if (isScalarLeaf(item)) {
                    lines.push(`${stars}${formatScalar(item)}`);
                    continue;
                }

                if (Array.isArray(item)) {
                    lines.push(`${"*".repeat(level - 1)}<${idx}>`);
                    writeListContents(item, level);
                    continue;
                }

                const itemKeys = Object.keys(item);

                if (itemKeys.length === 1) {
                    const key = itemKeys[0];
                    const value = item[key];

                    if (Array.isArray(value)) {
                        lines.push(`${stars}<${key}>`);
                        writeListContents(value, level + 1);
                    } else if (value !== null && typeof value === "object") {
                        lines.push(`${stars}<${key}>`);
                        writeObjectAsMultiKey(value, level + 1);
                    } else {
                        lines.push(`${stars}${key} ${formatScalar(value)}`);
                    }
                } else {
                    lines.push(`${stars}<>`);
                    writeObjectAsMultiKey(item, level + 1);
                }
            }
        }

        function writeObjectAsMultiKey(obj, level) {
            const stars = "*".repeat(level);
            for (const key of Object.keys(obj)) {
                const value = obj[key];

                if (Array.isArray(value)) {
                    lines.push(`${stars}<${key}>`);
                    writeListContents(value, level + 1);
                } else if (value !== null && typeof value === "object") {
                    lines.push(`${stars}<${key}>`);
                    writeObjectAsMultiKey(value, level + 1);
                } else {
                    lines.push(`${stars}${key} ${formatScalar(value)}`);
                }
            }
        }

        function writeSession(name, data) {
            lines.push(`[${name}]`);

            if (Array.isArray(data)) {
                if (isLeafArray(data)) {
                    for (const v of data) {
                        lines.push(`*${formatScalar(v)}`);
                    }
                } else {
                    for (let idx = 0; idx < data.length; idx++) {
                        const item = data[idx];
                        if (isScalarLeaf(item)) {
                            lines.push(`*${formatScalar(item)}`);
                        } else if (Array.isArray(item)) {
                            lines.push(`<${idx}>`);
                            writeListContents(item, 1);
                        } else {
                            const itemKeys = Object.keys(item);
                            if (itemKeys.length === 1) {
                                const key = itemKeys[0];
                                const value = item[key];
                                if (Array.isArray(value)) {
                                    lines.push(`<${key}>`);
                                    writeListContents(value, 1);
                                } else {
                                    lines.push("<>");
                                    writeObjectAsMultiKey(item, 1);
                                }
                            } else {
                                lines.push("<>");
                                writeObjectAsMultiKey(item, 1);
                            }
                        }
                    }
                }
                lines.push("");
                return;
            }

            if (data === null || typeof data !== "object") {
                throw new TypeError(
                    `A sessão "${name}" deve ser um objeto ou array.`
                );
            }

            for (const key of Object.keys(data)) {
                const value = data[key];

                if (Array.isArray(value)) {
                    lines.push(`<${key}>`);
                    writeListContents(value, 1);
                } else if (value !== null && typeof value === "object") {
                    lines.push(`<${key}>`);
                    writeObjectAsMultiKey(value, 1);
                } else {
                    writeScalarProperty(key, value);
                }
            }
            lines.push("");
        }

        for (const name of keys) {
            writeSession(name, input[name]);
        }

        while (lines.length && lines[lines.length - 1] === "") {
            lines.pop();
        }

        return lines.join("\n");
    }

    return { parse, stringify, JDataError };
});