$ErrorActionPreference = 'Stop'

function Assert-LastExitCode([string]$stepName) {
    if ($LASTEXITCODE -ne 0) {
        throw "Step failed: $stepName (exit code $LASTEXITCODE)"
    }
}

Write-Host '==> Backend tests'
Push-Location backend
php artisan test --stop-on-failure
Assert-LastExitCode 'Backend tests'
Pop-Location

Write-Host '==> Frontend check'
Push-Location frontend
cmd /c npm run check
Assert-LastExitCode 'Frontend check'
Pop-Location

Write-Host '==> Realtime check'
Push-Location realtime
cmd /c npm run check
Assert-LastExitCode 'Realtime check'
Pop-Location

Write-Host 'Quality gate passed.'
