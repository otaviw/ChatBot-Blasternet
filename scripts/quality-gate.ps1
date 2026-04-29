param(
    [int]$BackendCoverageMin = 60,
    [int]$FrontendCoverageMin = 60,
    [int]$RealtimeCoverageMin = 60
)

$ErrorActionPreference = 'Stop'

function Assert-LastExitCode([string]$stepName) {
    if ($LASTEXITCODE -ne 0) {
        throw "Step failed: $stepName (exit code $LASTEXITCODE)"
    }
}

Write-Host "Coverage thresholds => backend: $BackendCoverageMin% | frontend: $FrontendCoverageMin% | realtime: $RealtimeCoverageMin%"

Write-Host '==> Backend lint'
Push-Location backend
Get-ChildItem -Path app, bootstrap, config, database, routes, tests -Recurse -Filter *.php | ForEach-Object {
    php -l $_.FullName
    Assert-LastExitCode "Backend lint ($($_.FullName))"
}

Write-Host '==> Backend tests with coverage'
php vendor/bin/pest --coverage --min=$BackendCoverageMin
Assert-LastExitCode 'Backend tests with coverage'
Pop-Location

Write-Host '==> Frontend lint'
Push-Location frontend
cmd /c npm run lint
Assert-LastExitCode 'Frontend lint'

Write-Host '==> Frontend tests with coverage'
$env:FRONTEND_COVERAGE_MIN = "$FrontendCoverageMin"
cmd /c npm run test:coverage
Assert-LastExitCode 'Frontend tests with coverage'
Remove-Item Env:FRONTEND_COVERAGE_MIN -ErrorAction SilentlyContinue
Pop-Location

Write-Host '==> Realtime lint'
Push-Location realtime
cmd /c npm run lint
Assert-LastExitCode 'Realtime lint'

Write-Host '==> Realtime tests with coverage'
cmd /c npx c8 --check-coverage --lines=$RealtimeCoverageMin --functions=$RealtimeCoverageMin --branches=$RealtimeCoverageMin --reporter text-summary node --test
Assert-LastExitCode 'Realtime tests with coverage'
Pop-Location

Write-Host 'Quality gate passed.'
