import os
import sys

# Adiciona o diretório da sua aplicação ao path do Python
sys.path.insert(0, os.path.dirname(__file__))

# --- ATIVAÇÃO DO AMBIENTE VIRTUAL (venv) ---
# Este bloco é crucial. Ele garante que as bibliotecas instaladas no seu venv
# (como Flask, Google AI, etc.) sejam encontradas e utilizadas.
# Substitua 'python3.8' pela sua versão do Python, se for diferente.
# Você pode verificar a versão correta navegando até a pasta /venv/lib/
venv_path = "/home/magicpro/www/ai_service/venv/lib/python3.9/site-packages"
sys.path.insert(0, venv_path)
# --- FIM DA ATIVAÇÃO ---

# Importa a sua aplicação Flask do arquivo app.py
# A variável 'application' é o padrão que o Passenger procura.
from app import app as application