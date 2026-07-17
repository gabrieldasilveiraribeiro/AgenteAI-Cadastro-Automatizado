from google.cloud import vision
import os
import re
import math
import json

os.environ['GOOGLE_APPLICATION_CREDENTIALS'] = '/home/magicpro/www/ai_service/gcp-final-credentials.json'

_client = None

def get_vision_client():
    global _client
    if _client is None:
        _client = vision.ImageAnnotatorClient()
    return _client

def _normalize_number(v):
    try:
        if v is None: return None
        if isinstance(v, str):
            v = v.replace(',', '.').strip()
            if v == '': return None
        n = float(v)
        return n
    except Exception:
        return None

def _join_tags(existing_tags, new_tags):
    ex = existing_tags or []
    ne = new_tags or []
    merged = []
    seen = set()
    for t in (ex + ne):
        if not t: continue
        s = t.strip().lower()
        if s and s not in seen:
            merged.append(t.strip())
            seen.add(s)
    return merged

def _generate_name_from_labels(labels, ocr):
    if ocr:
        if isinstance(ocr, list):
            text = " ".join(ocr)
        else:
            text = str(ocr)
        candidate = text.split('\n')[0].strip()
        if 3 <= len(candidate) <= 140:
            return candidate
    if labels:
        if isinstance(labels, list):
            if labels and isinstance(labels[0], dict) and 'description' in labels[0]:
                names = [l.get('description','') for l in labels][:5]
            else:
                names = [str(x) for x in labels][:5]
        else:
            names = [str(labels)]
        return " / ".join([n for n in names if n])[:140]
    return "Produto"

def _generate_description(labels, web_entities, ocr):
    parts = []
    if ocr:
        txt = ocr if isinstance(ocr, str) else " ".join(ocr)
        txt_clean = re.sub(r'\s+', ' ', txt).strip()
        if len(txt_clean) > 20:
            parts.append(txt_clean)
    if labels:
        if isinstance(labels, list):
            labs = []
            for l in labels:
                if isinstance(l, dict):
                    labs.append(l.get('description',''))
                else:
                    labs.append(str(l))
            parts.append("Principais caracteristicas: " + ", ".join([x for x in labs if x][:8]))
    if web_entities:
        if isinstance(web_entities, list):
            we = []
            for w in web_entities[:6]:
                if isinstance(w, dict):
                    we.append(w.get('description','') or w.get('name',''))
                else:
                    we.append(str(w))
            if we:
                parts.append("Similar a: " + ", ".join([x for x in we if x]))
    return "\n\n".join(parts)[:5000]

def generate_product(labels, web_entities, ocr_text, existing_product=None):
    """
    Gera um dicionario produto baseado em entradas simples.
    Se existing_product for fornecido (dict vindo do DB), faz merge inteligente:
      - nome_item e descricao sempre sobrescrevem
      - campos numericos/medidas sao mantidos do existente se a IA nao oferecer valor valido
      - tags sao uniao sem duplicatas
    Retorna dict com as chaves: nome_item, descricao, categoria, tags (list), valor_inicial,
    altura_cm, largura_cm, comprimento_cm, peso_kg, confidence
    """
    nome = _generate_name_from_labels(labels, ocr_text)
    descricao = _generate_description(labels, web_entities, ocr_text)

    altura = None; largura = None; comprimento = None; peso = None; valor = None

    if isinstance(ocr_text, str) and ocr_text:
        txt = ocr_text.lower()
        m = re.search(r'(\d+[.,]?\d*)\s*(cm|mm|m)\b', txt)
        if m:
            v = m.group(1).replace(',', '.')
            altura = _normalize_number(v)
        m2 = re.search(r'(\d+[.,]?\d*)\s*kg\b', txt)
        if m2:
            peso = _normalize_number(m2.group(1).replace(',', '.'))
  
        mp = re.search(r'r\$?\s*(\d+[.,]?\d*)', txt)
        if mp:
            valor = _normalize_number(mp.group(1).replace(',', '.'))

    tags_new = []
    if labels:
        if isinstance(labels, list):
            for l in labels[:20]:
                if isinstance(l, dict):
                    tags_new.append(l.get('description') or l.get('mid') or '')
                else:
                    tags_new.append(str(l))
    if web_entities:
        if isinstance(web_entities, list):
            for w in web_entities[:20]:
                if isinstance(w, dict):
                    tags_new.append(w.get('description') or w.get('name') or '')
                else:
                    tags_new.append(str(w))

    if existing_product:
        ex = existing_product
        existing_tags = []
        if ex.get('tags'):
            try:
                t = ex.get('tags')
                if isinstance(t, str):
                    existing_tags = json.loads(t)
                elif isinstance(t, (list, tuple)):
                    existing_tags = list(t)
            except Exception:
                existing_tags = []

        tags = _join_tags(existing_tags, tags_new)

        categoria = None
        if ex.get('categoria'):
            try:
                categoria = int(ex.get('categoria')) if ex.get('categoria') else None
            except Exception:
                categoria = None
        categoria_final = categoria

        altura_ai = _normalize_number(altura)
        largura_ai = _normalize_number(largura)
        comprimento_ai = _normalize_number(comprimento)
        peso_ai = _normalize_number(peso)
        valor_ai = _normalize_number(valor)

        altura_final = altura_ai if altura_ai not in (None, 0.0) else ( _normalize_number(ex.get('altura')) or None )
        largura_final = largura_ai if largura_ai not in (None, 0.0) else ( _normalize_number(ex.get('largura')) or None )
        comprimento_final = comprimento_ai if comprimento_ai not in (None, 0.0) else ( _normalize_number(ex.get('comprimento')) or None )
        peso_final = peso_ai if peso_ai not in (None, 0.0) else ( _normalize_number(ex.get('peso')) or None )
        valor_final = valor_ai if valor_ai not in (None, 0.0) else ( _normalize_number(ex.get('valor_inicial')) or None )

        nome_final = nome
        descricao_final = descricao

        confidence = 75
        if (labels and len(labels) > 0) and (ocr_text and len(str(ocr_text).strip())>3):
            confidence = 92
        elif labels and len(labels)>0:
            confidence = 85
        elif ocr_text and len(str(ocr_text).strip())>5:
            confidence = 78

        return {
            'nome_item': nome_final,
            'descricao': descricao_final,
            'categoria': categoria_final,
            'tags': tags,
            'valor_inicial': float(valor_final) if valor_final not in (None, '') else None,
            'altura_cm': float(altura_final) if altura_final not in (None, '') else None,
            'largura_cm': float(largura_final) if largura_final not in (None, '') else None,
            'comprimento_cm': float(comprimento_final) if comprimento_final not in (None, '') else None,
            'peso_kg': float(peso_final) if peso_final not in (None, '') else None,
            'confidence': confidence
        }

    tags = _join_tags([], tags_new)
    confidence = 80 if labels else 60
    return {
        'nome_item': nome,
        'descricao': descricao,
        'categoria': None,
        'tags': tags,
        'valor_inicial': float(valor) if _normalize_number(valor) else 1.00,
        'altura_cm': float(altura) if _normalize_number(altura) else None,
        'largura_cm': float(largura) if _normalize_number(largura) else None,
        'comprimento_cm': float(comprimento) if _normalize_number(comprimento) else None,
        'peso_kg': float(peso) if _normalize_number(peso) else None,
        'confidence': confidence
    }



def analyze_image(path):
    client = get_vision_client()

    with open(path, 'rb') as f:
        content = f.read()

    image = vision.Image(content=content)

    labels_resp = client.label_detection(image=image)
    labels = [(l.description, float(l.score)) for l in labels_resp.label_annotations]

    web_resp = client.web_detection(image=image).web_detection
    web_entities = [(e.description, float(e.score)) for e in web_resp.web_entities] if web_resp.web_entities else []

    text_resp = client.text_detection(image=image)
    ocr_text = text_resp.text_annotations[0].description if text_resp.text_annotations else ""

    return {
        "labels": labels,
        "web_entities": web_entities,
        "ocr": ocr_text[:5000]
    }
