import mysql.connector
import os
import PyPDF2
import json
import time
import datetime
import schedule
import google.generativeai as genai
from google.generativeai.types import HarmCategory, HarmBlockThreshold

# Database configuration
DB_CONFIG = {
    "host": "localhost",
    "user": "root",
    "password": "",  # XAMPP default password is empty
    "database": "job_application_system_db"
}

# Google AI Studio API key
GOOGLE_API_KEY = ""  # Bu kısmı kendi API anahtarınızla değiştirin

# Web kök dizini - XAMPP kurulumunuza göre
BASE_DIRECTORY = r"C:\xampp\htdocs\jobBeta3"  # r öneki Windows path'lerindeki \ işaretlerini escape karakteri olarak algılanmasını önler

def get_full_path(relative_path):
    """Veritabanından gelen göreceli yolları tam dosya yoluna dönüştürür."""
    # Path'i normalize et ve birleştir
    # Windows sistemlerde / yerine \ kullanılır
    normalized_path = relative_path.replace('/', os.path.sep)
    full_path = os.path.join(BASE_DIRECTORY, normalized_path)
    return full_path

def extract_text_from_pdf(relative_path):
    """PDF dosyasından metin çıkarır"""
    try:
        # Tam dosya yolunu al
        full_path = get_full_path(relative_path)
        
        print(f"PDF okunuyor: {full_path}")
        
        # Dosyanın varlığını kontrol et
        if not os.path.exists(full_path):
            print(f"CV bulunamadı: {full_path}")
            return None
        
        # PDF'i oku
        text = ""
        with open(full_path, 'rb') as file:
            reader = PyPDF2.PdfReader(file)
            for page_num in range(len(reader.pages)):
                page_text = reader.pages[page_num].extract_text()
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
    """İş tanımını veritabanından çek"""
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
        
        # İş detaylarını birleştir
        full_description = f"İş Başlığı: {job['title']}\n"
        full_description += f"Konum: {job['location']}\n"
        full_description += f"Açıklama: {job['description']}"
        
        return full_description
    except Exception as e:
        print(f"İş tanımı çekilirken hata: {str(e)}")
        return None

def evaluate_cv_job_match(cv_text, job_description):
    """CV ve iş tanımını Google AI'a göndererek uyumluluğu değerlendirir"""
    try:
        # Google Generative AI'yi yapılandır
        genai.configure(api_key=GOOGLE_API_KEY)
        
        # Mevcut modelleri listele (hata ayıklama için)
        try:
            models = genai.list_models()
            print("Kullanılabilir modeller:")
            gemini_models = [model.name for model in models if "gemini" in model.name.lower()]
            print(gemini_models)
            
            # En uygun Gemini modeli seç
            if any("gemini-1.5-pro" in model for model in gemini_models):
                model_name = "gemini-1.5-pro"
            elif any("gemini-1.0-pro" in model for model in gemini_models):
                model_name = "gemini-1.0-pro"
            elif any("gemini-pro" in model for model in gemini_models):
                model_name = "gemini-pro"
            else:
                # Varsa ilk Gemini modelini kullan, yoksa varsayılan
                model_name = gemini_models[0] if gemini_models else "gemini-pro"
            
            print(f"Seçilen model: {model_name}")
        except Exception as e:
            print(f"Model listesi alınamadı: {str(e)}")
            model_name = "gemini-pro"  # Varsayılan
        
        # Güvenlik ayarlarını yapılandır
        safety_settings = {
            HarmCategory.HARM_CATEGORY_HARASSMENT: HarmBlockThreshold.BLOCK_NONE,
            HarmCategory.HARM_CATEGORY_HATE_SPEECH: HarmBlockThreshold.BLOCK_NONE,
            HarmCategory.HARM_CATEGORY_SEXUALLY_EXPLICIT: HarmBlockThreshold.BLOCK_NONE,
            HarmCategory.HARM_CATEGORY_DANGEROUS_CONTENT: HarmBlockThreshold.BLOCK_NONE,
        }
        
        # Modeli yapılandır
        model = genai.GenerativeModel(model_name=model_name, safety_settings=safety_settings)
        
        # CV-İş eşleşme değerlendirme promptu
        prompt = """
        Lütfen bu özgeçmişin (CV) verilen iş tanımına ne kadar uygun olduğunu değerlendir.
        
        İş Tanımı:
        {job_description}
        
        CV İçeriği:
        {cv_text}
        
        Şunları değerlendir:
        1. Beceri uyumu: Adayın becerileri iş gereksinimlerine ne kadar uygun?
        2. Deneyim ilgisi: Adayın deneyimi pozisyon için ne kadar ilgili?
        3. Eğitim uyumu: Adayın eğitim geçmişi iş ile ne kadar uyumlu?
        4. Genel uygunluk: Bu aday bu pozisyon için ne kadar uygun?
        
        Şunları sağla:
        1. 0 ile 100 arasında genel eşleşme yüzdesini temsil eden bir sayısal puan
        2. Güçlü yönleri, zayıflıkları ve adayın neden iyi bir eşleşme olduğunu/olmadığını açıklayan detaylı geri bildirim
        
        Cevabını bir JSON nesnesi olarak formatla:
        {{
            "score": (0-100 arası sayısal puan),
            "feedback": "detaylı geri bildirim metni"
        }}
        """.format(
            job_description=job_description,
            cv_text=cv_text
        )
        
        # AI'dan tamamlama iste
        print("AI'dan değerlendirme isteniyor...")
        response = model.generate_content(prompt)
        
        # Cevaptan JSON çıkar
        response_text = response.text
        print(f"AI yanıtı alındı. Uzunluk: {len(response_text)} karakter")
        
        # JSON yapısını ara
        json_start = response_text.find('{')
        json_end = response_text.rfind('}') + 1
        
        if json_start >= 0 and json_end > json_start:
            json_str = response_text[json_start:json_end]
            print(f"JSON yanıt bulundu: {json_str[:100]}...")
            result = json.loads(json_str)
            return result
        else:
            print("JSON yanıt bulunamadı. Yanıtı manuel analiz etme...")
            # JSON ayrıştırma başarısız olursa manuel olarak çıkarmaya çalış
            lines = response_text.split('\n')
            score = 0
            feedback = "Yapılandırılmış geri bildirim sağlanamadı. Ham yanıt: " + response_text[:500]
            
            for line in lines:
                if ("score" in line.lower() or "puan" in line.lower()) and ":" in line:
                    try:
                        score_text = line.split(":")[1].strip()
                        # Sadece sayıyı çıkarmaya çalış
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
    # Script başlangıç zamanı
    start_time = datetime.datetime.now()
    print(f"CV Değerlendirme başladı: {start_time}")
    
    try:
        # Veritabanına bağlan
        connection = mysql.connector.connect(**DB_CONFIG)
        cursor = connection.cursor(dictionary=True)
        
        # cv_score = 0 olan başvuruları bul
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
            
            # PDF'den metin çıkar
            cv_text = extract_text_from_pdf(cv_path)
            
            if not cv_text:
                print(f"Başvuru {app_id} için CV metni çıkarılamadı, atlanıyor")
                continue
            
            # İş tanımını al
            job_description = get_job_description(connection, job_id)
            
            if not job_description:
                print(f"İş ID {job_id} için iş tanımı alınamadı, atlanıyor")
                continue
            
            print("CV metni ve iş tanımı alındı, AI değerlendirmesi başlatılıyor...")
            
            # AI değerlendirmesi için gönder
            evaluation = evaluate_cv_job_match(cv_text, job_description)
            
            # Veritabanını güncelle
            score = evaluation.get('score', 0)
            feedback = evaluation.get('feedback', 'Geri bildirim sağlanamadı')
            
            print(f"Değerlendirme alındı - Puan: {score}")
            print(f"Geri Bildirim: {feedback[:150]}... (kısaltılmış)")
            
            cursor.execute("""
                UPDATE applications 
                SET cv_score = %s, cv_feedback = %s 
                WHERE id = %s
            """, (score, feedback, app_id))
            
            connection.commit()
            print(f"Başvuru {app_id} için veritabanı güncellendi")
            
            # API'yi fazla yüklememek için küçük bir gecikme ekle
            time.sleep(1)
            
        cursor.close()
        connection.close()
        print("\nİşlem tamamlandı!")
        
    except Exception as e:
        print(f"Başvuruları işlerken hata: {str(e)}")
    finally:
        end_time = datetime.datetime.now()
        print(f"CV Değerlendirme tamamlandı: {end_time}")
        print(f"Toplam süre: {end_time - start_time}")

def run_scheduled_job():
    """Zamanlanmış görevi çalıştır"""
    print(f"Zamanlanmış CV değerlendirme görevi başlatılıyor - {datetime.datetime.now()}")
    process_applications()

def main():
    """Ana program - zamanlama işlemlerini ayarla ve başlat"""
    print(f"CV Değerlendirme servisi başlatıldı: {datetime.datetime.now()}")
    print("Her saat başı çalışacak şekilde ayarlandı.")
    
    # İlk çalıştırma için şimdi çalıştır
    run_scheduled_job()
    
    # Her saat başı çalışacak şekilde zamanla
    schedule.every().hour.at(":00").do(run_scheduled_job)
    
    # Ana zamanlama döngüsü
    while True:
        schedule.run_pending()
        time.sleep(36000)  # Her saat başı kontrol et

if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        print("\nKullanıcı tarafından durduruldu")
    except Exception as e:
        print(f"Genel hata: {str(e)}")