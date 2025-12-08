import os
from flask import Flask, request, jsonify
import joblib
import numpy as np
import pandas as pd
from functools import wraps

app = Flask(__name__)

# =============================
#   🔐 API KEY PROTECTION
# =============================
API_KEY = os.environ.get("API_KEY", "dev_key_ganti_nanti")

def require_api_key(f):
    @wraps(f)
    def wrapper(*args, **kwargs):
        auth = request.headers.get("Authorization")

        if not auth or not auth.lower().startswith("bearer "):
            return jsonify({"error": "Unauthorized"}), 401

        token = auth.split(" ", 1)[1].strip()
        if token != API_KEY:
            return jsonify({"error": "Invalid API Key"}), 403

        return f(*args, **kwargs)
    return wrapper


# =================================--------------
#   Load model & supporting artifacts
# =================================--------------
MODEL_PATH = os.path.join(os.path.dirname(__file__), "model_kesesuaian.pkl")
ENCODER_PATH = os.path.join(os.path.dirname(__file__), "encoders_all.pkl")
FEATURE_PATH = os.path.join(os.path.dirname(__file__), "feature_list.pkl")

model = joblib.load(MODEL_PATH)
encoders = joblib.load(ENCODER_PATH)
feature_list = joblib.load(FEATURE_PATH)

# Fallback importance
try:
    global_importance = model.feature_importances_.astype(float)
except Exception:
    global_importance = np.ones(len(feature_list), dtype=float)

# Default means → nol
feature_means = np.zeros(len(feature_list), dtype=float)

# ==============================
#   Safe encoding
# ==============================
def safe_encode(column_name, value):
    enc = encoders.get(column_name)
    if enc is None:
        return value
    try:
        return enc.transform([str(value)])[0]
    except Exception:
        try:
            return enc.transform([enc.classes_[0]])[0]
        except Exception:
            return 0


# Friendly names
friendly_names = {
    "jurusan": "Jurusan Anda",
    "nilai_ujikom": "Nilai Uji Kompetensi",
    "nilai_kejuruan": "Nilai Kejuruan",
    "tempat_pkl_relevan": "Kesesuaian tempat PKL",
    "ekskul_aktif": "Keaktifan ekstrakurikuler",
    "status_tracer": "Status saat ini",
    "bidang_pekerjaan": "Bidang pekerjaan",
    "jabatan_pekerjaan": "Jabatan pekerjaan",
    "pendapatan": "Pendapatan"
}

advice_negative = {
    "nilai_ujikom": "Tingkatkan latihan praktik dan ikuti bimbingan agar nilai ujikom membaik.",
    "nilai_kejuruan": "Ikuti kursus tambahan atau praktik lapangan.",
    "tempat_pkl_relevan": "Cari pengalaman PKL yang lebih relevan.",
    "ekskul_aktif": "Ikuti kegiatan untuk meningkatkan soft skill.",
    "status_tracer": "Pertimbangkan aktivitas yang lebih mendukung karier.",
    "bidang_pekerjaan": "Cari bidang yang lebih selaras dengan kompetensi.",
    "jabatan_pekerjaan": "Upgrade skill untuk naik jabatan.",
    "pendapatan": "Tingkatkan pengalaman/skill untuk peluang pendapatan lebih baik."
}

advice_positive = {
    "nilai_ujikom": "Nilai ujikom bagus — pertahankan.",
    "nilai_kejuruan": "Nilai kejuruan mendukung — terus tingkatkan.",
    "tempat_pkl_relevan": "PKL relevan — tonjolkan saat melamar.",
    "ekskul_aktif": "Aktif ekstrakurikuler — pertahankan.",
    "status_tracer": "Status mendukung karier.",
    "bidang_pekerjaan": "Bidang pekerjaan sudah relevan.",
    "jabatan_pekerjaan": "Jabatan mendukung — bisa naik level.",
    "pendapatan": "Pendapatan mendukung — pertahankan."
}

group_map = {
    "Akademik": ["nilai_ujikom", "nilai_kejuruan", "jurusan"],
    "Pekerjaan": ["bidang_pekerjaan", "jabatan_pekerjaan", "pendapatan", "status_tracer"],
    "Pendukung": ["tempat_pkl_relevan", "ekskul_aktif"]
}

# ===============================================
#   🚀 API Endpoint (dengan API KEY)
# ===============================================
@app.route("/predict_kesesuaian", methods=["POST"])
@require_api_key
def predict_kesesuaian():
    try:
        data = request.get_json(force=True)

        required_fields = [
            "jurusan", "nilai_ujikom", "nilai_kejuruan",
            "tempat_pkl_relevan", "ekskul_aktif",
            "status_tracer", "bidang_pekerjaan",
            "jabatan_pekerjaan", "pendapatan"
        ]

        missing = [f for f in required_fields if f not in data]
        if missing:
            return jsonify({"error": f"Input tidak lengkap: {', '.join(missing)}"}), 400

        df_input = pd.DataFrame([{k: data[k] for k in required_fields}])

        for col in encoders.keys():
            if col not in df_input.columns:
                df_input[col] = 0
            df_input[col] = safe_encode(col, df_input[col].iloc[0])

        for feat in feature_list:
            if feat not in df_input.columns:
                df_input[feat] = 0

        df_input = df_input[feature_list].astype(float)

        pred = int(model.predict(df_input)[0])
        proba = model.predict_proba(df_input)[0] if hasattr(model, "predict_proba") else [0, 1]

        row_values = df_input.values.flatten().astype(float)
        diffs = row_values - feature_means
        norm_imp = global_importance / global_importance.sum()
        raw_contrib = diffs * norm_imp

        idx_sorted = np.argsort(np.abs(raw_contrib))[::-1]
        max_abs = max(abs(raw_contrib).max(), 1e-6)

        def severity(v):
            r = abs(v) / max_abs
            return "besar" if r >= 0.66 else "sedang" if r >= 0.33 else "kecil"

        grouped_reasons = {k: [] for k in group_map}
        others = []
        suggestions = []

        for i in idx_sorted:
            feat = feature_list[i]
            contrib = float(raw_contrib[i])
            readable = friendly_names.get(feat, feat)
            effect = "positif" if contrib > 0 else "negatif"
            sev = severity(contrib)

            msg = (
                f"{readable} berdampak positif (pengaruh {sev})."
                if contrib > 0
                else f"{readable} berdampak negatif (pengaruh {sev})."
            )

            reason = {"feature": feat, "label": readable, "effect": effect, "severity": sev, "message": msg}

            placed = False
            for g, feats in group_map.items():
                if feat in feats:
                    grouped_reasons[g].append(reason)
                    placed = True
                    break
            if not placed:
                others.append(reason)

            advice = advice_positive.get(feat) if contrib > 0 else advice_negative.get(feat)
            if advice and (readable + ": " + advice) not in suggestions:
                suggestions.append(readable + ": " + advice)

        if others:
            grouped_reasons["Lainnya"] = others

        resp = {
            "prediksi": "Sesuai" if pred == 1 else "Tidak Sesuai",
            "probability": {"tidak_sesuai": float(proba[0]), "sesuai": float(proba[1])},
            "grouped_reasons": grouped_reasons,
            "suggestions": suggestions
        }

        return jsonify(resp)

    except Exception as e:
        return jsonify({"error": str(e)}), 500


# ===========================
#   Run for Railway
# ===========================
if __name__ == "__main__":
    port = int(os.environ.get("PORT", 5002))
    app.run(host="0.0.0.0", port=port)
