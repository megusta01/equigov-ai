<#
.SYNOPSIS
  Equivalente ao Makefile para Windows/PowerShell.
.EXAMPLE
  .\tasks.ps1 install
  .\tasks.ps1 wp equigov seed
  .\tasks.ps1 test
#>
param(
    [Parameter(Position = 0)][string]$Task = 'help',
    [Parameter(Position = 1, ValueFromRemainingArguments = $true)][string[]]$Rest
)

$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot
$Plugin = 'wordpress/plugins/equigov-core'

function Ensure-Env {
    if (-not (Test-Path .env)) {
        Copy-Item .env.example .env
        Write-Host 'Criei .env a partir do exemplo. Revise as senhas.' -ForegroundColor Yellow
    }
}

function Read-Env {
    $vars = @{}
    Get-Content .env | Where-Object { $_ -match '^\s*[A-Za-z_][A-Za-z0-9_]*=' } | ForEach-Object {
        $k, $v = $_ -split '=', 2
        $vars[$k.Trim()] = $v.Trim()
    }
    return $vars
}

function Invoke-Checked([scriptblock]$Cmd) {
    & $Cmd
    if ($LASTEXITCODE -ne 0) { throw "Comando falhou (exit $LASTEXITCODE)" }
}

switch ($Task) {
    'up' {
        Ensure-Env
        Invoke-Checked { docker compose up -d --build }
    }
    'install' {
        Ensure-Env
        Invoke-Checked { docker compose up -d --build }
        $e = Read-Env
        Invoke-Checked {
            docker compose exec `
                -e "WP_URL=$($e.WP_URL)" -e "WP_ADMIN_USER=$($e.WP_ADMIN_USER)" `
                -e "WP_ADMIN_PASSWORD=$($e.WP_ADMIN_PASSWORD)" -e "WP_ADMIN_EMAIL=$($e.WP_ADMIN_EMAIL)" `
                wordpress bash /scripts/bootstrap.sh
        }
    }
    'down'  { docker compose down }
    'logs'  { docker compose logs -f --tail=100 }
    'shell' { docker compose exec wordpress bash }
    'wp'    { docker compose exec wordpress wp --allow-root @Rest }
    'test' {
        # PHP + Composer dentro de um container: não precisa instalar nada no Windows.
        Invoke-Checked {
            docker run --rm -v "${PWD}/${Plugin}:/app" -w /app composer:2 `
                sh -c 'composer install --no-interaction --quiet && vendor/bin/phpunit --testdox'
        }
    }
    'smoke' {
        # Integração: API real + banco real, dentro do container do WordPress.
        Invoke-Checked {
            docker compose exec wordpress wp --allow-root eval-file `
                wp-content/plugins/equigov-core/tests/smoke/api-smoke.php
        }
    }
    'ai-test' {
        Invoke-Checked { docker compose run --rm --no-deps ai-service python -m pytest }
    }
    'ai-lint' {
        Invoke-Checked { docker compose run --rm --no-deps ai-service ruff check app tests }
    }
    'ai-ingest' {
        Invoke-Checked { docker compose exec ai-service python -m app.cli ingest /corpus }
    }
    'ai-eval' {
        Invoke-Checked { docker compose exec ai-service python -m app.cli eval }
    }
    'ai-search' {
        Invoke-Checked { docker compose exec ai-service python -m app.cli search ($Rest -join ' ') }
    }
    'ai-logs' { docker compose logs -f --tail=100 ai-service }
    'lint' {
        Invoke-Checked {
            docker run --rm -v "${PWD}/${Plugin}:/app" -w /app php:8.3-cli `
                sh -c 'find src equigov-core.php -name "*.php" -print0 | xargs -0 -n1 php -l > /dev/null && echo "PHP ok"'
        }
    }
    'reset' {
        $ok = Read-Host 'Isso apaga o banco e todos os volumes. Digite SIM para confirmar'
        if ($ok -eq 'SIM') { docker compose down -v }
    }
    default {
        @'
Uso: .\tasks.ps1 <tarefa>

  up        Sobe os containers
  install   Sobe, instala WP, ativa o plugin e cria dados de demo
  down      Para os containers
  logs      Logs de todos os serviços
  shell     Shell no container do WordPress
  wp        WP-CLI. Ex: .\tasks.ps1 wp equigov seed
  test      PHPUnit (roda em container)
  smoke     Teste de integração da API (precisa do install antes)
  ai-test   Testes do serviço de IA (pytest)
  ai-lint   Lint do serviço de IA (ruff)
  ai-ingest Ingere os documentos de ai-service/corpus
  ai-eval   Avaliação da busca (hit@k, MRR)
  ai-search Busca no acervo. Ex: .\tasks.ps1 ai-search interprete de libras
  ai-logs   Logs do serviço de IA
  lint      Verifica sintaxe PHP (roda em container)
  reset     Apaga TUDO (inclusive o banco)
'@ | Write-Host
    }
}
