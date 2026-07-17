#!/bin/bash
cd /home/magicpro/www/ai_service
source venv/bin/activate
nohup python app.py > /home/magicpro/www/ai_service/flask.log 2>&1 &
