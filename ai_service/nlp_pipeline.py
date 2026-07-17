# -*- coding: utf-8 -*-
import os
import json
import re
import logging

logger = logging.getLogger(__name__)

# tenta importar sdk Gemini (varios paths possíveis)
genai = None
try:
    import google.generativeai as genai  # recomendado
except Exception:
    try:
        from google import genai  # fallback antigo
    except Exception:
        genai = None

def _normalize_number(s):
    if s is None:
        return None
    try:
        if isinstance(s, (int, float)):
            return float(s)
        s2 = re.sub(r'[^\d\-,\.]', '', str(s)).replace(',', '.')
        if s2.strip() == '':
            return None
        return float(s2)
    except Exception:
        return None

def _join_tags(existing, new_tags):
    out = []
    existing = existing or []
    new_tags = new_tags or []
    for t in existing + new_tags:
        if not t: continue
        t2 = str(t).strip().lower()
        if t2 and t2 not in out:
            out.append(t2)
    return out

def _heuristic_generate(labels, web_entities, ocr_text, existing=None):
    # seu fallback heurístico (ajustado)
    labels_list = [l[0] if isinstance(l, (list,tuple)) else str(l) for l in (labels or [])]
    nome = labels_list[0] if labels_list else (ocr_text.split('\n',1)[0] if ocr_text else 'Produto')
    descricao = ""
    if ocr_text:
        descricao += "**Texto detectado:**\n" + ocr_text[:800] + "\n\n"
    if labels_list:
        descricao += "**Sugestoes:** " + ", ".join(labels_list[:6])
    tags = _join_tags([], labels_list)
    # tenta extrair números simples
    altura = largura = comprimento = peso = None
    valor = None
    confidence = 70 if labels_list else 50
    return {
        'nome_item': nome,
        'descricao': descricao,
        'categoria': None,
        'tags': tags,
        'valor_inicial': valor,
        'altura_cm': altura,
        'largura_cm': largura,
        'comprimento_cm': comprimento,
        'peso_kg': peso,
        'confidence': confidence
    }

def generate_product(labels, web_entities, ocr, existing_product=None):
    """
    Gera produto a partir de labels/web/ocr.
    Se Gemini estiver disponivel, tenta a gerar via API.
    Caso contrario, usa heuristica local.
    Se existing_product for fornecido, fazemos merge inteligente:
      - campos que IA retornar como null/0 sao substituidos pelos valores EXISTENTES,
      - title/description sao sempre sobrescritos pela IA (por regra sua).
    """
    prompt = f"""
Voce e um especialista em marketing e catalogacao de produtos para um e-commerce.
Gere JSON com campos: nome_item, descricao, categoria (id ou nome), tags (lista), altura_cm, largura_cm, comprimento_cm, peso_kg, valor_inicial, confidence
Use portugues do Brasil. Se nao souber, coloque null.
LABELS: {labels}
WEB: {web_entities}
OCR: {ocr[:2000]}
"""
    # 1) Tentar Gemini
    text = None
    if genai is not None:
        try:
            api_key = os.environ.get('GEMINI_API_KEY') or os.environ.get('GOOGLE_API_KEY')
            if api_key:
                # configura se SDK expoe configure()
                if hasattr(genai, 'configure'):
                    genai.configure(api_key=api_key)
                # dois paths de uso possiveis:
                # path A (google.generativeai): genai.chat.completions.create(...)
                if hasattr(genai, 'chat'):
                    resp = genai.chat.completions.create(model="gemini-1.5-flash", messages=[{"author":"user","content":[{"type":"text","text":prompt}]}])
                    try:
                        text = resp.choices[0].message.content[0].text
                    except Exception:
                        text = str(resp)
                elif hasattr(genai, 'GenerativeModel'):
                    model = genai.GenerativeModel("gemini-1.5-flash")
                    resp = model.generate_content(prompt)
                    text = getattr(resp, 'text', str(resp))
                else:
                    # tentativa genai.generate_text
                    try:
                        resp = genai.generate_text(prompt=prompt, model="gemini-1.5-flash")
                        text = getattr(resp, 'text', None) or str(resp)
                    except Exception as e:
                        logger.warning("genai: fallback generate_text failed: %s", e)
            else:
                logger.warning("GEMINI_API_KEY nao encontrado no ambiente; usarei fallback heuristico.")
        except Exception as e:
            logger.exception("Falha ao chamar Gemini SDK: %s", e)

    # 2) Se recebemos texto da IA, tentar extrair JSON
    if text:
        try:
            clean = text.strip()
            clean = re.sub(r'```(?:json)?', '', clean)
            # encontrar JSON dentro do texto
            m = re.search(r'(\{.*\})', clean, re.DOTALL)
            if m:
                clean = m.group(1)
            data = json.loads(clean)
        except Exception as e:
            logger.warning("Erro parse JSON IA: %s; texto: %s", e, text[:1000])
            data = None
    else:
        data = None

    # 3) fallback heuristico caso necessario
    if not data:
        data = _heuristic_generate(labels, web_entities, ocr, existing_product)

    # 4) saneamento e merge com existing_product
    tags_new = data.get('tags') or []
    if existing_product:
        # pega tags existentes
        existing_tags = []
        try:
            t = existing_product.get('tags')
            if isinstance(t, str):
                existing_tags = json.loads(t)
            elif isinstance(t, (list,tuple)):
                existing_tags = list(t)
        except Exception:
            existing_tags = []
        tags = _join_tags(existing_tags, tags_new)
        # categoria: se IA der id/valor use, senão mantém existing
        categoria_ai = data.get('categoria')
        categoria_final = None
        try:
            if categoria_ai not in (None, '', 0):
                categoria_final = int(categoria_ai) if str(categoria_ai).isdigit() else categoria_ai
            else:
                categoria_final = existing_product.get('categoria') or None
        except Exception:
            categoria_final = existing_product.get('categoria') or None

        # valores numericos: se IA n deu, mantem existing
        def _num_choice(ai_val, ex_key):
            ai_n = _normalize_number(ai_val)
            if ai_n is not None and ai_n != 0.0:
                return ai_n
            ex_v = existing_product.get(ex_key)
            return _normalize_number(ex_v)

        altura_final = _num_choice(data.get('altura_cm'), 'altura')
        largura_final = _num_choice(data.get('largura_cm'), 'largura')
        comprimento_final = _num_choice(data.get('comprimento_cm'), 'comprimento')
        peso_final = _num_choice(data.get('peso_kg'), 'peso')
        valor_final = _num_choice(data.get('valor_inicial'), 'valor_inicial')

        # titulo/descricao: regra sua -> sobrescrever sempre (IA gera)
        nome_final = data.get('nome_item') or existing_product.get('nome_item')
        descricao_final = data.get('descricao') or existing_product.get('descricao')

        confidence = float(data.get('confidence', 50))

        return {
            'nome_item': nome_final,
            'descricao': descricao_final,
            'categoria': categoria_final,
            'tags': tags,
            'valor_inicial': float(valor_final) if valor_final is not None else None,
            'altura_cm': float(altura_final) if altura_final is not None else None,
            'largura_cm': float(largura_final) if largura_final is not None else None,
            'comprimento_cm': float(comprimento_final) if comprimento_final is not None else None,
            'peso_kg': float(peso_final) if peso_final is not None else None,
            'confidence': confidence
        }

    # 5) sem existing_product: retorno normal
    data['tags'] = data.get('tags') or []
    data['confidence'] = float(data.get('confidence', 50))
    return data
