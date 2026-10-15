<?php
declare(strict_types=1);

/**
 * libJData - Parser e serializador do formato JData.
 * PHP 8.1+
 *
 * Sintaxe:
 * # comentário
 * [Usuário]
 * $id 1
 * $nome João
 * <endereços>
 * *logradouro Rua Central
 * *bairro Centro
 * <contatos>
 * *<pessoal>
 * **tipo telefone
 * **valor 88999990000
 */
final class JData
{
    /**
     * Converte texto JData para arrays associativos PHP.
     * Cada sessão é representada por uma chave de primeiro nível.
     */
    public static function parse(string $text): array
    {
        $root = [];
        $session = null;
        $current = &$root;
        $listStack = [];
        $lineNumber = 0;

        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];

        foreach ($lines as $rawLine) {
            $lineNumber++;
            $line = trim($rawLine);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Uma sessão nova encerra qualquer lista aberta.
            if (preg_match('/^\[([^\]]+)\]$/u', $line, $m)) {
                $session = trim($m[1]);
                if ($session === '') {
                    throw new InvalidArgumentException("Sessão vazia na linha {$lineNumber}.");
                }
                if (!isset($root[$session]) || !is_array($root[$session])) {
                    $root[$session] = [];
                }
                $current = &$root[$session];
                $listStack = [];
                continue;
            }

            if ($session === null) {
                throw new InvalidArgumentException("Conteúdo fora de uma sessão na linha {$lineNumber}.");
            }

            // Propriedade escalar: $chave valor
            if (preg_match('/^\$([^\s]+)(?:\s+(.*))?$/u', $line, $m)) {
                if ($listStack !== []) {
                    throw new InvalidArgumentException("Propriedade \$ fora do nível permitido na linha {$lineNumber}.");
                }
                $key = $m[1];
                $value = self::decodeScalar($m[2] ?? '');
                $root[$session][$key] = $value;
                continue;
            }

            // Lista: <nome>, ou lista aninhada: *<nome>, **<nome>, ...
            if (preg_match('/^(\**)<([^<>]+)>$/u', $line, $m)) {
                $stars = strlen($m[1]);
                $name = trim($m[2]);
                if ($name === '') {
                    throw new InvalidArgumentException("Nome de lista vazio na linha {$lineNumber}.");
                }

                if ($stars === 0) {
                    if ($listStack !== []) {
                        // Uma lista de nível zero não pode ser aberta dentro de outra lista.
                        throw new InvalidArgumentException("Lista de nível raiz dentro de outra lista na linha {$lineNumber}.");
                    }
                    $root[$session][$name] = [];
                    $listStack[] = ['level' => 0, 'path' => [$name]];
                    continue;
                }

                $parentLevel = $stars - 1;
                while ($listStack !== [] && end($listStack)['level'] > $parentLevel) {
                    array_pop($listStack);
                }
                if ($listStack === [] || end($listStack)['level'] !== $parentLevel) {
                    throw new InvalidArgumentException("Nível de lista inválido na linha {$lineNumber}.");
                }

                $parentPath = end($listStack)['path'];
                $childPath = array_merge($parentPath, [$name]);
                $parentRef = self::referenceAtPath($root[$session], $parentPath);
                $parentRef[$name] = [];
                $listStack[] = ['level' => $stars, 'path' => $childPath];
                continue;
            }

            // Elemento de lista: *chave valor ou *Valor
            if (preg_match('/^(\*+)(?:\s*(.*))$/u', $line, $m)) {
                $stars = strlen($m[1]);
                $payload = trim($m[2]);

                if ($payload === '') {
                    // Elemento sem conteúdo não cria null e não altera o nível.
                    continue;
                }

                while ($listStack !== [] && end($listStack)['level'] > $stars - 1) {
                    array_pop($listStack);
                }
                if ($listStack === [] || end($listStack)['level'] !== $stars - 1) {
                    throw new InvalidArgumentException("Elemento de lista fora do nível esperado na linha {$lineNumber}.");
                }

                $path = end($listStack)['path'];
                $listRef = self::referenceAtPath($root[$session], $path);

                if (preg_match('/^([^\s]+)\s+(.+)$/us', $payload, $itemMatch)) {
                    $listRef[] = [$itemMatch[1] => self::decodeScalar($itemMatch[2])];
                } else {
                    $listRef[] = self::decodeScalar($payload);
                }
                continue;
            }

            throw new InvalidArgumentException("Sintaxe JData inválida na linha {$lineNumber}: {$line}");
        }

        return $root;
    }

    /**
     * Serializa arrays PHP para texto JData.
     * Espera um array de sessões: ['Usuário' => [...]].
     */
    public static function stringify(array $data): string
    {
        $lines = [];
        foreach ($data as $session => $values) {
            if (!is_string($session) || $session === '' || !is_array($values)) {
                throw new InvalidArgumentException('Cada sessão deve ter nome e conter um array.');
            }
            $lines[] = '[' . $session . ']';
            self::writeEntries($values, $lines, 0);
            $lines[] = '';
        }
        return rtrim(implode("\n", $lines)) . "\n";
    }

    public static function parseFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException("Não foi possível ler o arquivo: {$path}");
        }
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Falha ao carregar o arquivo: {$path}");
        }
        return self::parse($content);
    }

    public static function saveFile(string $path, array $data): void
    {
        $content = self::stringify($data);
        if (file_put_contents($path, $content, LOCK_EX) === false) {
            throw new RuntimeException("Falha ao salvar o arquivo: {$path}");
        }
    }

    private static function decodeScalar(string $value): string|int|float|bool|null
    {
        $value = trim($value);
        if ($value === 'true') return true;
        if ($value === 'false') return false;
        if ($value === 'null') return null;
        if (preg_match('/^-?(?:0|[1-9]\d*)$/', $value)) {
            $int = filter_var($value, FILTER_VALIDATE_INT);
            if ($int !== false) return $int;
        }
        if (preg_match('/^-?(?:\d+\.\d*|\.\d+)(?:[eE][+-]?\d+)?$|^-?\d+[eE][+-]?\d+$/', $value)) {
            return (float)$value;
        }
        return $value;
    }

    private static function encodeScalar(mixed $value): string
    {
        if ($value === null) return 'null';
        if (is_bool($value)) return $value ? 'true' : 'false';
        if (is_int($value) || is_float($value)) return (string)$value;
        if (is_string($value)) {
            if ($value === '' || preg_match('/^\s|\s$|[\r\n]/u', $value) || preg_match('/^(true|false|null|-?\d+(?:\.\d+)?)$/', $value)) {
                return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }
            return $value;
        }
        throw new InvalidArgumentException('Tipo de valor não suportado em JData.');
    }

    private static function writeEntries(array $entries, array &$lines, int $level): void
    {
        foreach ($entries as $key => $value) {
            if (is_array($value) && !array_is_list($value)) {
                // Arrays associativos aninhados são representados como listas nomeadas.
                $stars = str_repeat('*', $level);
                $lines[] = $stars . '<' . (string)$key . '>';
                self::writeEntries($value, $lines, $level + 1);
            } elseif (is_array($value) && array_is_list($value)) {
                $stars = str_repeat('*', $level);
                $lines[] = $stars . '<' . (string)$key . '>';
                foreach ($value as $item) {
                    $prefix = str_repeat('*', $level + 1);
                    if (is_array($item)) {
                        if (!array_is_list($item)) {
                            foreach ($item as $itemKey => $itemValue) {
                                if (is_array($itemValue)) {
                                    $lines[] = $prefix . '<' . (string)$itemKey . '>';
                                    self::writeEntries($itemValue, $lines, $level + 2);
                                } else {
                                    $lines[] = $prefix . (string)$itemKey . ' ' . self::encodeScalar($itemValue);
                                }
                            }
                        } else {
                            foreach ($item as $itemValue) {
                                $lines[] = $prefix . self::encodeScalar($itemValue);
                            }
                        }
                    } else {
                        $lines[] = $prefix . self::encodeScalar($item);
                    }
                }
            } else {
                if (!is_string($key)) {
                    throw new InvalidArgumentException('Propriedades precisam ter chaves textuais.');
                }
                if ($level === 0) {
                    $lines[] = '$' . $key . ' ' . self::encodeScalar($value);
                } else {
                    $lines[] = str_repeat('*', $level) . $key . ' ' . self::encodeScalar($value);
                }
            }
        }
    }

    /** Retorna referência a um array usando caminho de chaves. */
    private static function &referenceAtPath(array &$root, array $path): array
    {
        $ref = &$root;
        foreach ($path as $part) {
            if (!isset($ref[$part]) || !is_array($ref[$part])) {
                $ref[$part] = [];
            }
            $ref = &$ref[$part];
        }
        return $ref;
    }
}
