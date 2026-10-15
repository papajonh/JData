# JData
# Sintaxe JData

O **JData** é um formato de dados leve e legível por humanos, desenvolvido para representar informações estruturadas por meio de marcadores simples, sem a necessidade de chaves e vírgulas como no JSON.

Os arquivos utilizam a extensão `.jdata` e permitem organizar informações em sessões, propriedades, listas e listas aninhadas.

## 1. Comentários

Os comentários começam com o caractere `#` e são ignorados pelo interpretador.

```jdata
# Este é um comentário
[Usuário]
$id 1
$nome João
```

## 2. Sessões

Uma sessão começa com um nome entre colchetes `[]`. Ela agrupa propriedades e listas relacionadas.

```jdata
[Usuário]
$id 1
$nome João

[Endereço]
$cidade Tianguá
$estado Ceará
```

Nesse exemplo, `Usuário` e `Endereço` são sessões independentes.

## 3. Propriedades

As propriedades começam com `$`, seguido por uma chave e seu valor, separados por um espaço.

Sintaxe:

```jdata
$chave valor
```

Exemplo:

```jdata
[Usuário]
$id 1
$nome João
$idade 25
$ativo true
```

Os valores podem representar textos, números e valores booleanos, conforme as regras de conversão de tipos implementadas pela biblioteca.

## 4. Listas

Uma lista é iniciada por um nome delimitado pelos caracteres `<` e `>`.

```jdata
[Usuário]
<contatos>
*joao@example.com
*maria@example.com
```

Nesse exemplo, `contatos` é uma lista com dois elementos. Cada elemento começa com `*`.

Também é possível utilizar elementos com chaves:

```jdata
[Usuário]
<endereço>
*rua Rua Principal
*bairro Centro
*cep 62338-000
```

Cada elemento contém uma chave e um valor.

## 5. Listas aninhadas

O JData permite criar listas aninhadas utilizando asteriscos (`*`) para identificar os níveis hierárquicos. A quantidade de asteriscos determina a qual nível da estrutura uma lista ou um elemento pertence.

### 5.1. Abertura de listas

Uma lista é declarada utilizando `<nome>`. Para declarar uma lista filha, acrescenta-se um asterisco antes da abertura. Cada nível adicional exige mais um asterisco.

| Sintaxe | Significado |
|---|---|
| `<lista>` | Lista de nível 0 |
| `*<lista>` | Lista de nível 1 |
| `**<lista>` | Lista de nível 2 |
| `***<lista>` | Lista de nível 3 |

### 5.2. Elementos das listas

Os elementos utilizam um asterisco a mais que o nível da lista à qual pertencem.

| Sintaxe | Significado |
|---|---|
| `*valor` | Elemento da lista de nível 0 |
| `**valor` | Elemento da lista de nível 1 |
| `***valor` | Elemento da lista de nível 2 |
| `****valor` | Elemento da lista de nível 3 |

Os elementos podem conter apenas um valor ou uma chave seguida de um valor.

```jdata
[Exemplo]
<categorias>
*Eletrônicos
*Informática
*Periféricos
```

### 5.3. Exemplo de listas aninhadas

```jdata
[Empresa]
<departamentos>
*<desenvolvimento>
**nome Desenvolvimento
**<equipes>
***nome Backend
***nome Frontend
*<suporte>
**nome Suporte Técnico
**<equipes>
***nome Redes
***nome Sistemas
```

Nesse exemplo:

- `departamentos` é a lista de nível 0.
- `desenvolvimento` e `suporte` são listas de nível 1.
- `nome Desenvolvimento` e `nome Suporte Técnico` são elementos de nível 1.
- `equipes` é uma lista de nível 2 dentro de cada departamento.
- `nome Backend`, `nome Frontend`, `nome Redes` e `nome Sistemas` são elementos de nível 2.

### 5.4. Retorno aos níveis anteriores

O JData não utiliza marcadores explícitos de fechamento, como chaves ou tags finais. O retorno a um nível anterior é determinado pela redução da quantidade de asteriscos na próxima declaração.

Por exemplo, depois de elementos de nível 2 (`***`), um elemento de nível 1 (`**`) indica o retorno ao nível 1. Um novo elemento de nível 0 (`*`) indica o retorno à lista raiz.

A abertura de uma lista filha não encerra automaticamente sua lista pai. A estrutura pai permanece ativa, permitindo a criação de outros elementos ou listas filhas no mesmo nível.

### 5.5. Validação dos níveis

O interpretador deve validar a hierarquia das listas e de seus elementos:

- Cada elemento deve corresponder ao nível de uma lista aberta.
- Uma lista filha deve estar vinculada a uma lista pai válida.
- Não é permitido saltar níveis hierárquicos inexistentes.
- Uma redução no número de asteriscos representa o retorno a um nível anterior.
- Um elemento vazio, como `*` ou `**`, não deve gerar um valor `null` nem provocar um retorno automático de nível.
- Uma estrutura com níveis incompatíveis deve ser considerada inválida.

Essas regras garantem que o JData mantenha uma hierarquia previsível e consistente, independentemente da linguagem utilizada para interpretar o formato.

## 6. Exemplo completo

```jdata
# Informações do usuário
[Usuário]
$id 1
$nome João
$idade 25
$ativo true

<endereço>
*rua Rua Principal
*bairro Centro
*cidade Tianguá
*estado Ceará
*cep 62338-000

<contatos>
*email joao@example.com
*telefone 88999990000

[Preferências]
$idioma Português
$tema escuro
$notificações true
```

Esse exemplo demonstra o uso de comentários, múltiplas sessões, propriedades, listas e valores escalares.

## 7. Interpretação e serialização

As bibliotecas do JData disponibilizam duas operações principais:

- `parse()` — converte o texto JData em estruturas de dados nativas da linguagem.
- `stringify()` — converte estruturas de dados nativas em texto JData.

Exemplo em JavaScript:

```javascript

const textoJData = `
[Usuário]
$id 1
$nome João
$idade 25
`;

const dados = JData.parse(textoJData);

console.log(dados);

const texto = JData.stringify(dados);

console.log(texto);

# Para acessar uma propriedade:

console.log(dados["Usuário"].nome);

```

Exemplo em PHP:

```<?php

require_once 'jdata.php';

$textoJData = "
[Usuário]
\$id 1
\$nome João
\$idade 25
";

$dados = JData::parse($textoJData);

print_r($dados);

$texto = JData::stringify($dados);

echo $texto;

Para acessar uma propriedade:

echo $dados['Usuário']['nome'];

```

Exemplo em Python:

```Python

from jdata import parse, stringify

texto_jdata = """
[Usuário]
$id 1
$nome João
$idade 25
"""

dados = parse(texto_jdata)

print(dados)

texto = stringify(dados)

print(texto)

Para acessar uma propriedade:

print(dados["Usuário"]["nome"])

```

As implementações em PHP e Python seguem a mesma proposta, permitindo trabalhar com o formato em diferentes ambientes.

## 8. Princípios do projeto

- **Legibilidade:** marcadores simples facilitam a leitura e a edição manual.
- **Organização:** sessões, propriedades e listas aninhadas estruturam as informações.
- **Simplicidade:** reduz a necessidade de delimitadores como chaves e vírgulas.
- **Compatibilidade:** uma sintaxe compartilhada permite a troca de dados entre linguagens.
- **Extensibilidade:** o formato pode evoluir mantendo uma especificação consistente.

O objetivo do JData é oferecer um formato textual prático para representar, armazenar e compartilhar dados estruturados entre diferentes aplicações e linguagens de programação.
