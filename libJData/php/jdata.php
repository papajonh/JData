<?php

class JDataError extends Exception
{
    public $line;

    public function __construct($message, $line = null)
    {
        $this->line = $line;
        parent::__construct($line ? "{$message} (linha {$line})" : $message);
    }
}

class JData
{
    // ---------------------------------------------------------------
    // PARSE
    // ---------------------------------------------------------------
    public static function parse($source)
    {
        if (!is_string($source)) {
            throw new TypeError("JData::parse espera uma string.");
        }

        $source = preg_replace('/^\xEF\xBB\xBF/', '', $source);
        $lines = preg_split('/\r?\n/', $source);

        $result = [];
        $stack = [];
        $currentSession = null;
        $sessionMode = null; // "object" | "scalarArray" | "nestedArray"

        $fail = function ($message, $lineNumber) {
            throw new JDataError($message, $lineNumber);
        };

        $requireList = function ($level, $lineNumber) use (&$stack, $fail) {
            if (!isset($stack[$level - 1])) {
                $fail("Não existe lista aberta no nível {$level}.", $lineNumber);
            }
            return $stack[$level - 1];
        };

        $ensureSessionMode = function ($mode, $lineNumber) use (
            &$sessionMode,
            &$result,
            &$currentSession,
            $fail
        ) {
            if ($sessionMode === null) {
                $sessionMode = $mode;
                $result[$currentSession] = ($mode === "object") ? [] : [];
                return;
            }
            if ($sessionMode !== $mode) {
                if ($sessionMode === "object" && $mode === "scalarArray") {
                    if (!isset($result[$currentSession]["0"]) ||
                        !is_array($result[$currentSession]["0"])) {
                        $result[$currentSession]["0"] = [];
                    }
                    return;
                }
                $fail(
                    "A sessão \"{$currentSession}\" já foi iniciada como {$sessionMode}; não pode virar {$mode}.",
                    $lineNumber
                );
            }
        };

        $pushAnonArray = function (&$parent, $lineNumber) use (&$stack, $fail) {
            if (!empty($parent["isMultiKey"])) {
                $key = (string) count($parent["array"]);
                $parent["array"][$key] = [];
                $stack[] = [
                    "name" => $key,
                    "array" => &$parent["array"][$key],
                    "isMultiKey" => false,
                    "isAnonArray" => true,
                ];
            } else {
                $anon = [];
                $parent["array"][] = &$anon;
                $stack[] = [
                    "name" => null,
                    "array" => &$anon,
                    "isMultiKey" => false,
                    "isAnonArray" => true,
                ];
            }
        };

        $resolvePending = function (&$pending, $line, $lineNumber) use (&$stack, $fail) {
            $isKeyValue = preg_match('/^\*+[^\s*<>]+\s+/', $line);
            $isChildList = preg_match('/^\*+<[^<>]+>$/', $line);
            $isChildAnon = preg_match('/^\*+<>$/', $line);
            $isScalar = preg_match('/^\*+[^\s*<>]+$/', $line);

            if ($isKeyValue || $isChildList || $isChildAnon) {
                $pending["holder"][$pending["name"]] = [];
                $stack = array_slice($stack, 0, $pending["level"]);
                $stack[] = [
                    "name" => $pending["name"],
                    "array" => &$pending["holder"][$pending["name"]],
                    "isMultiKey" => true,
                ];
            } elseif ($isScalar) {
                $pending["holder"][$pending["name"]] = [];
                $stack = array_slice($stack, 0, $pending["level"]);
                $stack[] = [
                    "name" => $pending["name"],
                    "array" => &$pending["holder"][$pending["name"]],
                    "isMultiKey" => false,
                ];
            } else {
                $fail("Não foi possível determinar o tipo da lista.", $lineNumber);
            }
        };

        foreach ($lines as $i => $rawLine) {
            $lineNumber = $i + 1;
            $line = trim($rawLine);
            if ($line === "" || $line[0] === "#") continue;

            // Sessão
            if (preg_match('/^\[([^\]]+)\]$/', $line, $m)) {
                $name = trim($m[1]);
                if ($name === "") $fail("O nome da sessão não pode ser vazio.", $lineNumber);
                if (array_key_exists($name, $result)) {
                    $fail("A sessão \"{$name}\" já foi declarada.", $lineNumber);
                }
                $currentSession = $name;
                $sessionMode = null;
                $result[$name] = null;
                $stack = [];
                continue;
            }

            if ($currentSession === null) {
                $fail("Declare uma sessão usando [Nome].", $lineNumber);
            }

            // Lista anônima na sessão: <>
            if ($line === "<>") {
                if ($sessionMode === null) {
                    $sessionMode = "nestedArray";
                    $result[$currentSession] = [];
                }
                if ($sessionMode !== "nestedArray") {
                    $fail(
                        "A sessão \"{$currentSession}\" não é uma lista de listas.",
                        $lineNumber
                    );
                }
                $anon = [];
                $result[$currentSession][] = &$anon;
                $stack = [[
                    "name" => null,
                    "array" => &$anon,
                    "isMultiKey" => false,
                    "isAnonArray" => true,
                ]];
                continue;
            }

            // Propriedade
            if ($line[0] === '$') {
                if (!preg_match('/^\$([^\s]+)(?:\s+([\s\S]*))?$/', $line, $m)) {
                    $fail("Linha \$ inválida.", $lineNumber);
                }
                $ensureSessionMode("object", $lineNumber);
                $result[$currentSession][$m[1]] = self::parseScalar($m[2] ?? "");
                $stack = [];
                continue;
            }

            // Lista nomeada no topo da sessão
            if (preg_match('/^<([^<>]+)>$/', $line, $m)) {
                $name = trim($m[1]);
                if ($name === "") $fail("O nome da lista não pode ser vazio.", $lineNumber);

                if (self::isNumericName($name)) {
                    if ($sessionMode === null) {
                        $sessionMode = "nestedArray";
                        $result[$currentSession] = [];
                    }
                    if (!is_array($result[$currentSession]) ||
                        !self::isListArray($result[$currentSession])) {
                        $fail(
                            "A sessão \"{$currentSession}\" já foi iniciada como objeto; não pode conter <{$name}> anônimo.",
                            $lineNumber
                        );
                    }
                    $anon = [];
                    $result[$currentSession][] = &$anon;
                    $stack = [[
                        "name" => null,
                        "array" => &$anon,
                        "isMultiKey" => false,
                        "isAnonArray" => true,
                    ]];
                    continue;
                }

                $ensureSessionMode("object", $lineNumber);
                $result[$currentSession][$name] = null;
                $holder = &$result[$currentSession];
                $stack = [[
                    "name" => $name,
                    "array" => null,
                    "isMultiKey" => false,
                    "pending" => true,
                    "holder" => &$holder,
                    "level" => 0,
                ]];
                continue;
            }

            // Elementos e listas filhas
            if (!preg_match('/^(\*+)(.*)$/', $line, $m)) {
                $fail("Sintaxe inválida. Use \$, <lista> ou *elemento.", $lineNumber);
            }

            $level = strlen($m[1]);
            $content = trim($m[2]);
            if ($content === "") $fail("Elemento vazio não é permitido.", $lineNumber);

            // Resolve pendências
            if (count($stack) > 0 && !empty($stack[count($stack) - 1]["pending"])) {
                $top = &$stack[count($stack) - 1];
                if ($top["level"] + 1 === $level) {
                    $resolvePending($top, $line, $lineNumber);
                }
                unset($top);
            }

            // *<nome> ou *<>
            if (preg_match('/^<([^<>]*)>$/', $content, $cm)) {
                $parent = &$requireList($level, $lineNumber);
                $childName = trim($cm[1]);
                $stack = array_slice($stack, 0, $level);

                if ($childName === "") {
                    $pushAnonArray($parent, $lineNumber);
                } elseif (self::isNumericName($childName) && empty($parent["isMultiKey"])) {
                    $anon = [];
                    $parent["array"][] = &$anon;
                    $stack[] = [
                        "name" => null,
                        "array" => &$anon,
                        "isMultiKey" => false,
                        "isAnonArray" => true,
                    ];
                } elseif (self::isNumericName($childName) && !empty($parent["isMultiKey"])) {
                    $parent["array"][$childName] = [];
                    $stack[] = [
                        "name" => $childName,
                        "array" => &$parent["array"][$childName],
                        "isMultiKey" => false,
                    ];
                } else {
                    if (!empty($parent["isMultiKey"])) {
                        $parent["array"][$childName] = null;
                        $stack[] = [
                            "name" => $childName,
                            "array" => null,
                            "isMultiKey" => false,
                            "pending" => true,
                            "holder" => &$parent["array"],
                            "level" => $level,
                        ];
                    } else {
                        $childObject = [$childName => null];
                        $parent["array"][] = &$childObject;
                        $stack[] = [
                            "name" => $childName,
                            "array" => null,
                            "isMultiKey" => false,
                            "pending" => true,
                            "holder" => &$childObject,
                            "level" => $level,
                        ];
                    }
                }
                unset($parent);
                continue;
            }

            // *solto sem lista aberta
            if (count($stack) === 0) {
                if ($sessionMode === "object") {
                    if (!isset($result[$currentSession]["0"]) ||
                        !is_array($result[$currentSession]["0"])) {
                        $result[$currentSession]["0"] = [];
                    }
                    $result[$currentSession]["0"][] = self::parseScalar($content);
                } else {
                    $ensureSessionMode("scalarArray", $lineNumber);
                    $result[$currentSession][] = self::parseScalar($content);
                }
                continue;
            }

            $currentList = &$requireList($level, $lineNumber);
            $stack = array_slice($stack, 0, $level);

            if (!preg_match('/^([^\s]+)(?:\s+([\s\S]*))?$/', $content, $pm)) {
                $fail("Elemento inválido.", $lineNumber);
            }

            $key = $pm[1];
            $hasValue = isset($pm[2]);

            if (!empty($currentList["isMultiKey"])) {
                $currentList["array"][$key] = $hasValue
                    ? self::parseScalar($pm[2])
                    : null;
            } elseif (!$hasValue) {
                $currentList["array"][] = self::parseScalar($key);
            } else {
                $currentList["array"][] = [$key => self::parseScalar($pm[2])];
            }
            unset($currentList);
        }

        if ($currentSession === null) {
            throw new JDataError("Nenhuma sessão foi declarada.");
        }

        foreach ($result as $k => $v) {
            if ($v === null) $result[$k] = [];
        }

        return $result;
    }

    // ---------------------------------------------------------------
    // STRINGIFY
    // ---------------------------------------------------------------
    public static function stringify($input, $options = [])
    {
        if (!is_array($input) || self::isListArray($input)) {
            throw new TypeError("JData::stringify espera um objeto (array associativo).");
        }

        $keys = array_keys($input);
        if (count($keys) === 0) {
            throw new TypeError("O objeto raiz está vazio.");
        }

        $lines = [];

        $writeListContents = function ($array, $level) use (&$lines, &$writeObjectAsMultiKey) {
            $idx = 0;
            foreach ($array as $item) {
                $stars = str_repeat("*", $level);

                if (self::isScalarLeaf($item)) {
                    $lines[] = $stars . self::formatScalar($item);
                    $idx++;
                    continue;
                }

                if (is_array($item) && self::isListArray($item)) {
                    $lines[] = str_repeat("*", $level - 1) . "<{$idx}>";
                    $writeListContents($item, $level);
                    $idx++;
                    continue;
                }

                // objeto
                if (is_array($item)) {
                    $itemKeys = array_keys($item);
                    if (count($itemKeys) === 1) {
                        $key = $itemKeys[0];
                        $value = $item[$key];
                        if (is_array($value) && self::isListArray($value)) {
                            $lines[] = $stars . "<{$key}>";
                            $writeListContents($value, $level + 1);
                        } elseif (is_array($value)) {
                            $lines[] = $stars . "<{$key}>";
                            $writeObjectAsMultiKey($value, $level + 1);
                        } else {
                            $lines[] = $stars . $key . " " . self::formatScalar($value);
                        }
                    } else {
                        $lines[] = $stars . "<>";
                        $writeObjectAsMultiKey($item, $level + 1);
                    }
                }
                $idx++;
            }
        };

        $writeObjectAsMultiKey = function ($obj, $level) use (&$lines, &$writeListContents, &$writeObjectAsMultiKey) {
            $stars = str_repeat("*", $level);
            foreach ($obj as $key => $value) {
                if (is_array($value) && self::isListArray($value)) {
                    $lines[] = $stars . "<{$key}>";
                    $writeListContents($value, $level + 1);
                } elseif (is_array($value)) {
                    $lines[] = $stars . "<{$key}>";
                    $writeObjectAsMultiKey($value, $level + 1);
                } else {
                    $lines[] = $stars . $key . " " . self::formatScalar($value);
                }
            }
        };

        $writeSession = function ($name, $data) use (&$lines, &$writeListContents, &$writeObjectAsMultiKey) {
            $lines[] = "[{$name}]";

            if (is_array($data) && self::isListArray($data)) {
                if (self::isLeafArray($data)) {
                    foreach ($data as $v) {
                        $lines[] = "*" . self::formatScalar($v);
                    }
                } else {
                    $idx = 0;
                    foreach ($data as $item) {
                        if (self::isScalarLeaf($item)) {
                            $lines[] = "*" . self::formatScalar($item);
                        } elseif (is_array($item) && self::isListArray($item)) {
                            $lines[] = "<{$idx}>";
                            $writeListContents($item, 1);
                        } else {
                            $itemKeys = array_keys($item);
                            if (count($itemKeys) === 1) {
                                $key = $itemKeys[0];
                                $value = $item[$key];
                                if (is_array($value) && self::isListArray($value)) {
                                    $lines[] = "<{$key}>";
                                    $writeListContents($value, 1);
                                } else {
                                    $lines[] = "<>";
                                    $writeObjectAsMultiKey($item, 1);
                                }
                            } else {
                                $lines[] = "<>";
                                $writeObjectAsMultiKey($item, 1);
                            }
                        }
                        $idx++;
                    }
                }
                $lines[] = "";
                return;
            }

            if (!is_array($data)) {
                throw new TypeError(
                    "A sessão \"{$name}\" deve ser um objeto ou array."
                );
            }

            foreach ($data as $key => $value) {
                if (is_array($value) && self::isListArray($value)) {
                    $lines[] = "<{$key}>";
                    $writeListContents($value, 1);
                } elseif (is_array($value)) {
                    $lines[] = "<{$key}>";
                    $writeObjectAsMultiKey($value, 1);
                } else {
                    $lines[] = "\${$key} " . self::formatScalar($value);
                }
            }
            $lines[] = "";
        };

        foreach ($keys as $name) {
            $writeSession($name, $input[$name]);
        }

        while (count($lines) > 0 && end($lines) === "") {
            array_pop($lines);
        }

        return implode("\n", $lines);
    }

    // ---------------------------------------------------------------
    // HELPERS
    // ---------------------------------------------------------------
    private static function parseScalar($value)
    {
        $text = trim($value);
        if ($text === "") return "";

        if (strlen($text) >= 2 && $text[0] === '"' && substr($text, -1) === '"') {
            $decoded = json_decode($text, true);
            return $decoded !== null ? $decoded : substr($text, 1, -1);
        }
        if ($text === "true") return true;
        if ($text === "false") return false;
        if ($text === "null") return null;
        if (preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?$/', $text)) {
            return $text + 0;
        }
        return $text;
    }

    private static function formatScalar($value)
    {
        if (is_string($value)) {
            if (
                $value === "" ||
                preg_match('/^(true|false|null)$/', $value) ||
                preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?$/', $value) ||
                $value !== trim($value) ||
                (strlen($value) > 0 && $value[0] === '"')
            ) {
                return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            return $value;
        }
        if ($value === null) return "null";
        if (is_bool($value)) return $value ? "true" : "false";
        if (is_int($value) || is_float($value)) return (string) $value;
        throw new TypeError("Valor não suportado como escalar.");
    }

    private static function isScalarLeaf($value)
    {
        return $value === null || is_string($value) || is_int($value) || is_float($value) || is_bool($value);
    }

    private static function isLeafArray($array)
    {
        foreach ($array as $v) {
            if (!self::isScalarLeaf($v)) return false;
        }
        return true;
    }

    private static function isListArray($array)
    {
        if (!is_array($array)) return false;
        $i = 0;
        foreach ($array as $k => $_) {
            if ($k !== $i++) return false;
        }
        return true;
    }

    private static function isNumericName($name)
    {
        return preg_match('/^\d+$/', $name) === 1;
    }
}