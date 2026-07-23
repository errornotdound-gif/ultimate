#!/bin/bash

# ============================================
# RAILWAY MAILER EXPLOIT - DEPLOYMENT SCRIPT
# ============================================

set -e

echo "🚀 RAILWAY MAILER EXPLOIT - DEPLOYMENT"
echo "========================================"
echo ""

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}[1/6]${NC} Creating directories..."
mkdir -p logs
chmod 755 logs
echo -e "${GREEN}✅ Directories created${NC}"

echo -e "${BLUE}[2/6]${NC} Creating targets file..."
cat > targets.txt << 'EOF'
admin@example.com,Admin
user1@gmail.com,John Doe
user2@yahoo.com,Jane Smith
user3@hotmail.com,Robert Johnson
user4@outlook.com,Emily Davis
user5@protonmail.com,Michael Wilson
user6@mail.com,Sarah Brown
user7@yandex.com,David Miller
user8@aol.com,Lisa Anderson
user9@icloud.com,James Taylor
user10@zoho.com,Patricia Thomas
test1@example.org,Test User1
test2@example.net,Test User2
test3@example.co.uk,Test User3
test4@example.io,Test User4
test5@example.me,Test User5
test6@example.info,Test User6
test7@example.biz,Test User7
test8@example.tv,Test User8
test9@example.mobi,Test User9
test10@example.name,Test User10
EOF
echo -e "${GREEN}✅ Targets file created ($(wc -l < targets.txt) emails)${NC}"

echo -e "${BLUE}[3/6]${NC} Creating .env file..."
cat > .env << 'EOF'
APP_ENV=production
APP_DEBUG=false
SMTP_HOST=localhost
SMTP_PORT=25
FROM_EMAIL=system@railway.local
FROM_NAME=Railway System
BATCH_SIZE=100
DELAY_BETWEEN=0.5
USE_QUEUE=true
MAX_RETRIES=3
TIMEOUT=30
ENABLE_LOGGING=true
SPOOF_HEADERS=true
ROTATE_IP=true
RANDOM_MESSAGE_ID=true
USE_FAKE_MAILER=true
EOF
echo -e "${GREEN}✅ .env file created${NC}"

echo -e "${BLUE}[4/6]${NC} Installing dependencies..."
if command -v composer &> /dev/null; then
    composer install --no-dev --optimize-autoloader
    echo -e "${GREEN}✅ Dependencies installed${NC}"
else
    echo -e "${YELLOW}⚠️ Composer not found, skipping...${NC}"
fi

echo -e "${BLUE}[5/6]${NC} Setting permissions..."
chmod +x mailer.php
chmod 755 .
echo -e "${GREEN}✅ Permissions set${NC}"

echo -e "${BLUE}[6/6]${NC} Starting mailer..."
echo ""
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}✅ DEPLOYMENT COMPLETE!${NC}"
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo -e "${YELLOW}📊 Status:${NC}"
echo "  - Targets loaded: $(wc -l < targets.txt) emails"
echo "  - Logs directory: logs/"
echo "  - Environment: .env"
echo ""
echo -e "${BLUE}🚀 Starting mailer...${NC}"
echo ""

# Start the mailer
php mailer.php