from dotenv import load_dotenv
import os

load_dotenv('/home/magicpro/www/ai_service/.env')
os.environ.setdefault("GOOGLE_APPLICATION_CREDENTIALS",
                      "/home/magicpro/www/ai_service/gcp-final-credentials.json")

from flask import Flask, request, jsonify
from settings import *
from db import get_conn
from vision_pipeline import analyze_image
from nlp_pipeline import generate_product
import json
import os
import time
import logging
import traceback

import mysql.connector

os.makedirs('logs', exist_ok=True)
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler("logs/ai_service.log"),
        logging.StreamHandler()
    ]
)

app = Flask(__name__)

def execute_with_retry(conn, cur, sql, params=(), retries=3, delay=1.0):
    """Executa cur.execute com retry simples para lock-wait timeouts."""
    attempt = 0
    while True:
        try:
            cur.execute(sql, params)
            return
        except Exception as e:
            msg = str(e)
            attempt += 1
            if attempt <= retries and ('Lock wait timeout' in msg or '1205' in msg):
                logging.warning(f"Lock wait timeout detectado. Tentando novamente {attempt}/{retries} apos {delay}s...")
                try:
                    conn.rollback()
                except Exception:
                    pass
                time.sleep(delay)
                delay *= 2
                continue
            logging.error("Erro SQL nao recuperavel: " + msg)
            raise

@app.route('/health', methods=['GET'])
def health():
    return jsonify(ok=True)

@app.route('/process_job', methods=['POST'])
def process_job():
    job_id = int(request.form.get('job_id', 0))
    if not job_id:
        logging.error("Request recebido sem job_id")
        return jsonify(ok=False, msg='job_id invalido'), 400

    logging.info(f"Iniciando processamento para o Job #{job_id}")
    conn = get_conn()
    cur = conn.cursor()
    cur_dict = conn.cursor(dictionary=True)  
    try:
        
        execute_with_retry(conn, cur, "UPDATE ai_jobs SET status='processing' WHERE id=%s", (job_id,))

       
        cur.execute("SELECT id, src_path, source_product_id FROM ai_job_images WHERE job_id=%s", (job_id,))
        rows = cur.fetchall()
        logging.info(f"Job #{job_id}: Encontradas {len(rows)} imagens para processar.")
        
        products_created = 0

       
        for r in rows:
            if isinstance(r, dict):
                img_id = r.get('id')
                path = r.get('src_path')
                source_pid = r.get('source_product_id')
            else:
                if len(r) == 3:
                    img_id, path, source_pid = r
                elif len(r) == 2:
                    img_id, path = r
                    source_pid = None
                else:
                    img_id = r[0]; path = r[1]; source_pid = None

            logging.info(f"Job #{job_id}: Processando imagem ID #{img_id} (source_product_id={source_pid})")
            meta = analyze_image(path or "")

            try:
                jmeta = json.dumps(meta, ensure_ascii=False)
            except Exception:
                jmeta = json.dumps(meta)

            execute_with_retry(conn, cur, "UPDATE ai_job_images SET meta=%s WHERE id=%s", (jmeta, img_id))
            conn.commit()

            labels_list = meta.get("labels", [])
            web_list = meta.get("web_entities", [])
            ocr_all = meta.get("ocr", "")

            existing_product = None
            if source_pid:
                try:
                    cur_dict.execute("SELECT * FROM leilao_itens WHERE id=%s LIMIT 1", (source_pid,))
                    existing_product = cur_dict.fetchone()
                    logging.info(f"Job #{job_id}: produto origem encontrado id={source_pid}")
                except Exception as ex:
                    logging.warning(f"Falha ao buscar produto origem {source_pid}: {ex}")

            prod = generate_product(labels_list, web_list, ocr_all, existing_product=existing_product)
            requires_review = 1 if prod.get('confidence', 0) < 85 else 0

            ins_sql = """
                INSERT INTO ai_job_products
                (job_id, cluster_id, nome_item, descricao, categoria, tags,
                 valor_inicial, altura, largura, comprimento, peso,
                 imagem_capa, imagens, confidence, requires_review, source_product_id, status)
                VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,'draft')
            """
            tags_json = json.dumps(prod.get('tags', []), ensure_ascii=False)
            images_json = json.dumps([path] if path else [], ensure_ascii=False)
            params = (
                job_id, products_created,
                prod.get('nome_item'), prod.get('descricao'),
                prod.get('categoria'), tags_json,
                prod.get('valor_inicial'), prod.get('altura_cm'), prod.get('largura_cm'),
                prod.get('comprimento_cm'), prod.get('peso_kg'), path,
                images_json,
                prod.get('confidence', 0),
                requires_review,
                source_pid
            )

            execute_with_retry(conn, cur, ins_sql, params)
            products_created += 1
            logging.info(f"Job #{job_id}: Produto para a imagem ID #{img_id} inserido na tabela de staging.")

        conn.commit()

        # finalizar job
        execute_with_retry(conn, cur, "UPDATE ai_jobs SET status='done', total_products=%s, finished_at=NOW() WHERE id=%s", (products_created, job_id))
        conn.commit()
        logging.info(f"Job #{job_id} concluido com sucesso. {products_created} produtos criados.")
        return jsonify(ok=True, job_id=job_id, products=products_created)

    except Exception as e:
        logging.error(f"Erro CRITICO no processamento do Job #{job_id}:")
        logging.error(traceback.format_exc())

        try:
            if conn and conn.is_connected():
                conn.rollback()
                # tenta gravar status error (pode falhar se lock)
                try:
                    cur.execute("UPDATE ai_jobs SET status='error', errors=%s WHERE id=%s", (json.dumps({'error': str(e)}), job_id))
                    conn.commit()
                except Exception:
                    logging.error("Falha ao marcar job como error.")
        except Exception:
            pass

        return jsonify(ok=False, msg='erro no processamento', error=str(e)), 500

    finally:
        try:
            if conn and conn.is_connected():
                cur.close()
                cur_dict.close()
                conn.close()
        except Exception:
            pass

if __name__ == '__main__':
    app.run(host='127.0.0.1', port=5001, debug=True)
