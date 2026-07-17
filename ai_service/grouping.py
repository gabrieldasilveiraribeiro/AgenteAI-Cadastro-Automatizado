import numpy as np
from sklearn.cluster import DBSCAN

def vectorize(meta, vocabulary):
    # meta: {"labels":[(desc,score),...], "web_entities":[...]}
    vec = np.zeros(len(vocabulary))
    for desc, score in meta.get("labels", []) + meta.get("web_entities", []):
        desc = desc.lower().strip()
        if desc in vocabulary:
            vec[vocabulary[desc]] = max(vec[vocabulary[desc]], score)
    return vec

def build_vocabulary(metas, top_n=200):
    from collections import Counter
    c = Counter()
    for m in metas:
        for d,s in m.get("labels", []) + m.get("web_entities", []):
            c[d.lower().strip()] += s
    common = [w for w,_ in c.most_common(top_n)]
    return {w:i for i,w in enumerate(common)}

def clusterize(metas):
    # metas: lista de dicts por imagem
    vocab = build_vocabulary(metas)
    X = np.array([vectorize(m, vocab) for m in metas])
    if len(X) == 0:
        return []
    # DBSCAN com métrica de cosseno (eps calibrável)
    model = DBSCAN(eps=0.4, min_samples=1, metric='cosine')
    labels = model.fit_predict(X)
    return labels.tolist()
