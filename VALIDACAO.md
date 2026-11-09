# Validação da entrega

Verificado em 09/11/2026 com PHP 8.4.27 e PostgreSQL 17.11, usando bancos locais descartáveis.

- 98 verificações HTTP de integração aprovadas: autenticação, CSRF, papéis, CRUD, duplicidades, datas, status, geração anual, feriados, escala, histórico, arquivos privados, impressão, PDF, alteração de senha e revogação de acesso.
- 12 verificações de interação no DOM aprovadas com JavaScript executado e APIs reais: calendário, médico com dias fixos, agendamento, confirmação/cancelamento, feriado, abreviação/legenda, escala e usuários. Nenhum erro de execução JavaScript nesses fluxos. A ferramenta de teste fica fora do pacote; ela não é uma dependência da aplicação.
- Os 15 arquivos PHP passaram pela verificação de sintaxe; os três scripts JavaScript também passaram pela verificação de sintaxe do Node.
- Migração validada em banco vazio e em banco contendo as sete tabelas do SQL fornecido. Repetir a migração foi validado sem reaplicar alterações.
- PDF aberto com leitor de PDF por código e conferido quanto a acentos, observações, status e páginas de detalhes.
- Respostas de erro adicionais verificadas para JSON inválido, Content-Type incorreto, booleano inválido, rota inexistente e método não aceito.

## Limites da verificação

A aparência no Chrome, a responsividade visual e a janela física de impressão não foram verificadas. O ambiente informou desktop habilitado com estado `unknown`, mas não disponibilizou a ferramenta `cua_repl.js`/controle de navegador. Instância: `ccarenv_b64_Y2NhcmVudl83ZTZhYTA3NWIzMmM4MTkxYTFlOTY3OWY5ZjVkYTAzZg`.

Os arquivos Docker/Compose, Apache e Nginx foram fornecidos como opções de instalação e não foram executados nesta validação. O sistema foi executado e testado diretamente com PHP e PostgreSQL. Nenhuma publicação em hospedagem externa foi realizada.
