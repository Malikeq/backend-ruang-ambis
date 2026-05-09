# AI Lolos PTN — Backend Setup Script
# Run from: awda/backend directory
# Usage: .\setup.ps1

param(
    [switch]$Fresh = $false,
    [switch]$SkipImport = $false
)

$ErrorActionPreference = "Stop"

Write-Host ""
Write-Host "╔══════════════════════════════════════════╗" -ForegroundColor Cyan
Write-Host "║   AI Lolos PTN — Backend Setup           ║" -ForegroundColor Cyan
Write-Host "╚══════════════════════════════════════════╝" -ForegroundColor Cyan
Write-Host ""

# 1. Generate key if missing
Write-Host "[1/6] Generating app key..." -ForegroundColor Yellow
php artisan key:generate --no-interaction 2>$null

# 2. Create database
Write-Host "[2/6] Creating database 'ailolos_ptn'..." -ForegroundColor Yellow
php artisan db:create 2>$null
if ($LASTEXITCODE -ne 0) {
    Write-Host "      (db:create not available, creating manually via MySQL...)" -ForegroundColor Gray
    mysql -u root -e "CREATE DATABASE IF NOT EXISTS ailolos_ptn CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>$null
}

# 3. Run migrations
Write-Host "[3/6] Running migrations..." -ForegroundColor Yellow
if ($Fresh) {
    php artisan migrate:fresh --force
} else {
    php artisan migrate --force
}

# 4. Seed database
Write-Host "[4/6] Seeding database (users, mapel, kampus from API, packages, soal)..." -ForegroundColor Yellow
php artisan db:seed --force

# 5. Import kampus from api.co.id (optional)
if (-not $SkipImport) {
    Write-Host "[5/6] Importing additional PTN kampus from api.co.id..." -ForegroundColor Yellow
    php artisan kampus:import --group=PTN --size=500
} else {
    Write-Host "[5/6] Skipping API import (fallback data already seeded)." -ForegroundColor Gray
}

# 6. Storage link
Write-Host "[6/6] Creating storage link..." -ForegroundColor Yellow
php artisan storage:link 2>$null

Write-Host ""
Write-Host "✅ Backend setup complete!" -ForegroundColor Green
Write-Host ""
Write-Host "📋 Demo Credentials:" -ForegroundColor Cyan
Write-Host "   Admin: admin@ailolosiptn.com / admin123!" -ForegroundColor White
Write-Host "   User:  demo@ailolosiptn.com  / demo123!" -ForegroundColor White
Write-Host ""
Write-Host "🚀 Start server: php artisan serve --port=8000" -ForegroundColor Yellow
Write-Host ""
