import mysql.connector
import os
import PyPDF2
import json
import time
import datetime
import google.generativeai as genai
from google.generativeai.types import HarmCategory, HarmBlockThreshold

# Script başlangıç zamanı
start_time = datetime.datetime.now()
print(f"CV Değerlendirme başladı: {start_time}")

# Database configuration
DB_CONFIG = {
    "host": "localhost",
    "user": "root",
    "password": "",  # XAMPP default password is empty
    "database": "job_application_system_db"
}

# Google AI Studio API key
GOOGLE_API_KEY = "AIzaSyAWepBv1S6iNuyouJiu_V2JJgwZAYl6iC8"  # API anahtarınızı buraya ekleyin

BASE_DIRECTORY = r"C:\xampp\htdocs\jobBeta3"

def get_full_path(relative_path):
    """Veritabanından gelen göreceli yolları tam dosya yoluna dönüştürür."""
    normalized_path = relative_path.replace('/', os.path.sep)
    return os.path.join(BASE_DIRECTORY, normalized_path)

def extract_text_from_pdf(relative_path):
    """PDF dosyasından metin çıkarır"""
    try:
        full_path = get_full_path(relative_path)
        print(f"PDF okunuyor: {full_path}")

        if not os.path.exists(full_path):
            print(f"CV bulunamadı: {full_path}")
            return None

        text = ""
        with open(full_path, 'rb') as file:
            reader = PyPDF2.PdfReader(file)
            for page in reader.pages:
                page_text = page.extract_text()
                if page_text:
                    text += page_text

        if not text:
            print(f"PDF'den metin çıkarılamadı: {full_path}")
            return None

        return text
    except Exception as e:
        print(f"PDF okuma hatası ({full_path}): {str(e)}")
        return None

def get_job_description(connection, job_id):
    """İş tanımını veritabanından çeker"""
    try:
        cursor = connection.cursor(dictionary=True)
        cursor.execute("""
            SELECT title, description, location
            FROM jobs
            WHERE id = %s
        """, (job_id,))

        job = cursor.fetchone()
        cursor.close()

        if not job:
            print(f"İş ID {job_id} bulunamadı")
            return None

        full_description = (
            f"İş Başlığı: {job['title']}\n"
            f"Konum: {job['location']}\n"
            f"Açıklama: {job['description']}"
        )
        return full_description
    except Exception as e:
        print(f"İş tanımı çekilirken hata: {str(e)}")
        return None

def evaluate_cv_job_match(cv_text, job_description):
    """CV ve iş tanımını Google AI'a göndererek uyumluluğu değerlendirir"""
    try:
        genai.configure(api_key=GOOGLE_API_KEY)

        try:
            models = genai.list_models()
            gemini_models = [model.name for model in models if "gemini" in model.name.lower()]
            model_name = next((m for m in gemini_models if "gemini-1.5-pro" in m),
                              next((m for m in gemini_models if "gemini-1.0-pro" in m),
                                   gemini_models[0] if gemini_models else "gemini-pro"))
            print(f"Seçilen model: {model_name}")
        except Exception as e:
            print(f"Model listesi alınamadı: {str(e)}")
            model_name = "gemini-pro"

        safety_settings = {
            HarmCategory.HARM_CATEGORY_HARASSMENT: HarmBlockThreshold.BLOCK_NONE,
            HarmCategory.HARM_CATEGORY_HATE_SPEECH: HarmBlockThreshold.BLOCK_NONE,
            HarmCategory.HARM_CATEGORY_SEXUALLY_EXPLICIT: HarmBlockThreshold.BLOCK_NONE,
            HarmCategory.HARM_CATEGORY_DANGEROUS_CONTENT: HarmBlockThreshold.BLOCK_NONE,
        }

        model = genai.GenerativeModel(model_name=model_name, safety_settings=safety_settings)

        prompt = (
            "Lütfen bu özgeçmişin (CV) verilen iş tanımına ne kadar uygun olduğunu değerlendir.\n\n"
            f"İş Tanımı:\n{job_description}\n\n"
            f"CV İçeriği:\n{cv_text}\n\n"
            "Şunları değerlendir:\n"
            "1. Beceri uyumu: Adayın becerileri iş gereksinimlerine ne kadar uygun?\n"
            "2. Deneyim ilgisi: Adayın deneyimi pozisyon için ne kadar ilgili?\n"
            "3. Eğitim uyumu: Adayın eğitim geçmişi iş ile ne kadar uyumlu?\n"
            "4. Genel uygunluk: Bu aday bu pozisyon için ne kadar uygun?\n\n"
            "Şunları sağla:\n"
            "1. 0 ile 100 arasında genel eşleşme yüzdesini temsil eden bir sayısal puan\n"
            "2. Güçlü yönleri, zayıflıkları ve adayın neden iyi bir eşleşme olduğunu/olmadığını açıklayan detaylı geri bildirim\n\n"
            "Cevabını bir JSON nesnesi olarak formatla:\n"
            "{\n"
            "    \"score\": (0-100 arası sayısal puan),\n"
            "    \"feedback\": \"detaylı geri bildirim metni\"\n"
            "}"
        )

        print("AI'dan değerlendirme isteniyor...")
        response = model.generate_content(prompt)
        response_text = response.text
        print(f"AI yanıtı alındı. Uzunluk: {len(response_text)} karakter")

        json_start = response_text.find('{')
        json_end = response_text.rfind('}') + 1

        if json_start >= 0 and json_end > json_start:
            json_str = response_text[json_start:json_end]
            print(f"JSON yanıt bulundu: {json_str[:100]}...")
            return json.loads(json_str)
        else:
            print("JSON yanıt bulunamadı. Yanıtı manuel analiz etme...")
            lines = response_text.split('\n')
            score = 0
            feedback = "Yapılandırılmış geri bildirim sağlanamadı. Ham yanıt: " + response_text[:500]

            for line in lines:
                if ("score" in line.lower() or "puan" in line.lower()) and ":" in line:
                    try:
                        score_text = line.split(":")[1].strip()
                        score = int(''.join(filter(str.isdigit, score_text.split()[0])))
                        print(f"Puan bulundu: {score}")
                    except:
                        pass
                if "feedback" in line.lower() or "geri bildirim" in line.lower() and ":" in line:
                    feedback = line.split(":", 1)[1].strip()
                    print(f"Geri bildirim bulundu: {feedback[:100]}...")

            return {"score": score, "feedback": feedback}
    except Exception as e:
        print(f"AI değerlendirme hatası: {str(e)}")
        return {"score": 0, "feedback": f"Değerlendirme sırasında hata: {str(e)}"}

def process_applications():
    """cv_score = 0 olan başvuruları işle, CV'yi iş tanımına göre değerlendir"""
    try:
        connection = mysql.connector.connect(**DB_CONFIG)
        cursor = connection.cursor(dictionary=True)

        cursor.execute("""
            SELECT a.id, a.cv_path, a.job_id, j.title as job_title
            FROM applications a
            JOIN jobs j ON a.job_id = j.id
            WHERE a.cv_score = 0
        """)

        applications = cursor.fetchall()
        print(f"{len(applications)} adet CV değerlendirilecek.")

        for app in applications:
            app_id = app['id']
            cv_path = app['cv_path']
            job_id = app['job_id']
            job_title = app['job_title']

            print(f"\nBaşvuru ID: {app_id} işleniyor")
            print(f"İş ID: {job_id}, İş Başlığı: {job_title}")

            cv_text = extract_text_from_pdf(cv_path)

            if not cv_text:
                print(f"Başvuru {app_id} için CV metni çıkarılamadı, atlanıyor")
                continue

            job_description = get_job_description(connection, job_id)

            if not job_description:
                print(f"İş ID {job_id} için iş tanımı alınamadı, atlanıyor")
                continue

            print("CV metni ve iş tanımı alındı, AI değerlendirmesi başlatılıyor...")

            evaluation = evaluate_cv_job_match(cv_text, job_description)

            score = evaluation.get('score', 0)
            feedback = evaluation.get('feedback', 'Geri bildirim sağlanamadı')

            print(f"Değerlendirme alındı - Puan: {score}")
            print(f"Geri Bildirim: {feedback[:150]}... (kısaltılmış)")

            try:
                cursor.execute("""
                    UPDATE applications
                    SET cv_score = %s, cv_feedback = %s
                    WHERE id = %s
                """, (score, feedback, app_id))

                connection.commit()
                print(f"Başvuru {app_id} için veritabanı güncellendi")

            except Exception as e:
                print(f"Veritabanı güncelleme hatası (Başvuru ID: {app_id}): {str(e)}")
                connection.rollback()

            time.sleep(1)

        cursor.close()
        connection.close()
        print("\nİşlem tamamlandı!")

    except Exception as e:
        print(f"Başvuruları işlerken hata: {str(e)}")

if __name__ == "__main__":
    try:
        process_applications()
    except Exception as e:
        print(f"Genel hata: {str(e)}")
    finally:
        end_time = datetime.datetime.now()
        print(f"CV Değerlendirme tamamlandı: {end_time}")
