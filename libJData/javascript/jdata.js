
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

        if (
            text.startsWith('"') &&
            text.endsWith('"')
        ) {
            try {
                return JSON.parse(text);
            } catch {
                return text.slice(1, -1);
            }
        }

        if (text === "true") return true;
        if (text === "false") return false;
        if (text === "null") return null;

        if (/^-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?$/.test(text)) {
            const number = Number(text);
            if (Number.isFinite(number)) return number;
        }

        return text;
    }

    function formatScalar(value) {
        if (typeof value === "string") {
            // Strings que poderiam ser interpretadas como outros tipos
            // são serializadas entre aspas.
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

        if (
            value === null ||
            typeof value === "number" ||
            typeof value === "boolean"
        ) {
            return String(value);
        }

        throw new TypeError("Valor não suportado como escalar.");
    }

    function parse(source) {
        if (typeof source !== "string") {
            throw new TypeError("JData.parse espera uma string.");
        }

        const lines = source.replace(/^\uFEFF/, "").split(/\r?\n/);
        const result = {};
        const stack = [];
        let rootName = null;

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

        for (let i = 0; i < lines.length; i++) {
            const lineNumber = i + 1;
            const line = lines[i].trim();

            if (!line || line.startsWith("#")) continue;

            // Objeto raiz: [Usuário]
            const rootMatch = line.match(/^\[([^\]]+)\]$/);

            if (rootMatch) {
                if (rootName !== null) {
                    fail("Já existe um objeto raiz declarado.", lineNumber);
                }

                rootName = rootMatch[1].trim();

                if (!rootName) {
                    fail("O nome do objeto raiz não pode ser vazio.", lineNumber);
                }

                stack.length = 0;
                continue;
            }

            if (rootName === null) {
                fail("Declare o objeto raiz usando [Nome].", lineNumber);
            }

            // Propriedade do objeto raiz: $nome valor
            if (line.startsWith("$")) {
                const match = line.match(/^\$([^\s]+)(?:\s+([\s\S]*))?$/);

                if (!match) {
                    fail("Propriedade inválida.", lineNumber);
                }

                const key = match[1];
                const rawValue = match[2] ?? "";

                result[key] = parseScalar(rawValue);
                stack.length = 0;
                continue;
            }

            // Lista principal: <endereços>
            const rootListMatch = line.match(/^<([^<>]+)>$/);

            if (rootListMatch) {
                const name = rootListMatch[1].trim();

                if (!name) {
                    fail("O nome da lista não pode ser vazio.", lineNumber);
                }

                result[name] = [];
                stack.length = 0;
                stack.push({ name, array: result[name] });
                continue;
            }

            // Elementos e listas filhas: *, **, ***, etc.
            const itemMatch = line.match(/^(\*+)(.*)$/);

            if (!itemMatch) {
                fail("Sintaxe inválida. Use $, <lista> ou *elemento.", lineNumber);
            }

            const level = itemMatch[1].length;
            const content = itemMatch[2].trim();

            if (!content) {
                fail("Elemento vazio não é permitido.", lineNumber);
            }

            // Uma lista filha, por exemplo: *<alternativos>
            const childListMatch = content.match(/^<([^<>]+)>$/);

            if (childListMatch) {
                const parent = requireList(level, lineNumber);
                const childName = childListMatch[1].trim();

                if (!childName) {
                    fail("O nome da lista filha não pode ser vazio.", lineNumber);
                }

                // Fecha os níveis mais profundos antes de abrir outro.
                stack.length = level;

                const childObject = { [childName]: [] };
                parent.array.push(childObject);

                stack.push({
                    name: childName,
                    array: childObject[childName]
                });

                continue;
            }

            const currentList = requireList(level, lineNumber);

            // Reduzir o número de asteriscos fecha os níveis internos.
            stack.length = level;

            // Elemento no formato *chave valor.
            // Se não houver separação, é tratado como escalar.
            const propertyMatch = content.match(/^([^\s]+)(?:\s+([\s\S]*))?$/);

            if (!propertyMatch) {
                fail("Elemento inválido.", lineNumber);
            }

            const key = propertyMatch[1];
            const hasValue = propertyMatch[2] !== undefined;

            if (!hasValue) {
                currentList.array.push(parseScalar(key));
            } else {
                currentList.array.push({
                    [key]: parseScalar(propertyMatch[2])
                });
            }
        }

        if (rootName === null) {
            throw new JDataError("Nenhum objeto raiz foi declarado.");
        }

        return { [rootName]: result };
    }

    function stringify(input, options = {}) {
        if (
            input === null ||
            typeof input !== "object" ||
            Array.isArray(input)
        ) {
            throw new TypeError("JData.stringify espera um objeto.");
        }

        const keys = Object.keys(input);

        if (keys.length !== 1) {
            throw new TypeError(
                "O objeto raiz deve conter apenas uma propriedade, como { Usuário: {...} }."
            );
        }

        const rootName = keys[0];
        const data = input[rootName];

        if (
            data === null ||
            typeof data !== "object" ||
            Array.isArray(data)
        ) {
            throw new TypeError("O conteúdo do objeto raiz deve ser um objeto.");
        }

        const lines = [];
        const indent = options.indentComments !== false;

        lines.push(`[${rootName}]`);

        function writeScalar(key, value) {
            if (value !== null && typeof value === "object") {
                throw new TypeError(
                    `A propriedade "${key}" não é escalar. Objetos devem ser representados por listas.`
                );
            }

            lines.push(`$${key} ${formatScalar(value)}`);
        }

        function writeList(name, array, level) {
            if (!Array.isArray(array)) {
                throw new TypeError(`"${name}" deve ser uma lista.`);
            }

            if (level === 1) {
                lines.push(`<${name}>`);
            } else {
                lines.push(`${"*".repeat(level - 1)}<${name}>`);
            }

            for (const item of array) {
                const stars = "*".repeat(level);

                if (item !== null && typeof item === "object") {
                    if (Array.isArray(item)) {
                        throw new TypeError(
                            "Listas aninhadas precisam ter um nome de propriedade."
                        );
                    }

                    const itemKeys = Object.keys(item);

                    if (itemKeys.length !== 1) {
                        throw new TypeError(
                            "Cada elemento de lista deve ter exatamente uma propriedade."
                        );
                    }

                    const key = itemKeys[0];
                    const value = item[key];

                    if (Array.isArray(value)) {
                        lines.push(`${stars}<${key}>`);
                        writeListContents(value, level + 1);
                    } else if (value !== null && typeof value === "object") {
                        throw new TypeError(
                            `O elemento "${key}" contém um objeto não suportado.`
                        );
                    } else {
                        lines.push(`${stars}${key} ${formatScalar(value)}`);
                    }
                } else {
                    lines.push(`${stars}${formatScalar(item)}`);
                }
            }
        }

        function writeListContents(array, level) {
            for (const item of array) {
                const stars = "*".repeat(level);

                if (item !== null && typeof item === "object") {
                    if (Array.isArray(item)) {
                        throw new TypeError(
                            "Listas aninhadas precisam ter um nome de propriedade."
                        );
                    }

                    const itemKeys = Object.keys(item);

                    if (itemKeys.length !== 1) {
                        throw new TypeError(
                            "Cada elemento de lista deve ter exatamente uma propriedade."
                        );
                    }

                    const key = itemKeys[0];
                    const value = item[key];

                    if (Array.isArray(value)) {
                        lines.push(`${stars}<${key}>`);
                        writeListContents(value, level + 1);
                    } else if (value !== null && typeof value === "object") {
                        throw new TypeError(
                            `O elemento "${key}" contém um objeto não suportado.`
                        );
                    } else {
                        lines.push(`${stars}${key} ${formatScalar(value)}`);
                    }
                } else {
                    lines.push(`${stars}${formatScalar(item)}`);
                }
            }
        }

        for (const key of Object.keys(data)) {
            const value = data[key];

            if (Array.isArray(value)) {
                writeList(key, value, 1);
            } else {
                writeScalar(key, value);
            }
        }

        return lines.join("\n");
    }

    return {
        parse,
        stringify,
        JDataError
    };
});
