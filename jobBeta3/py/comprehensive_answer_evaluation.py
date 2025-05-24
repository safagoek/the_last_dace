import mysql.connector
import os
import PyPDF2
import json
import time
import datetime
import re
import google.generativeai as genai
from google.generativeai.types import HarmCategory, HarmBlockThreshold

# Script başlangıç zamanı
start_time = datetime.datetime.now()
print(f"Cevap Değerlendirme Sistemi başladı: {start_time}")

# Database configuration
DB_CONFIG = {
    "host": "localhost",
    "user": "root",
    "password": "",  # XAMPP default password is empty
    "database": "job_application_system_db"
}

# Google AI Studio API key
GOOGLE_API_KEY = "AIzaSyAWepBv1S6iNuyouJiu_V2JJgwZAYl6iC8"  # Bu kısmı kendi API anahtarınızla değiştirin

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
            print(f"Dosya bulunamadı: {full_path}")
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

def get_question_text(connection, question_id):
    """Soru metnini veritabanından çeker"""
    try:
        cursor = connection.cursor(dictionary=True)
        cursor.execute("""
            SELECT q.question_text, q.question_type, j.title as job_title, j.description as job_description 
            FROM questions q
            JOIN jobs j ON q.job_id = j.id
            WHERE q.id = %s
        """, (question_id,))
        
        question_data = cursor.fetchone()
        cursor.close()
        
        if not question_data:
            print(f"Soru ID {question_id} bulunamadı")
            return None
        
        # Soru detaylarını birleştir
        context = f"İş Başlığı: {question_data['job_title']}\n"
        context += f"İş Tanımı: {question_data['job_description']}\n\n"
        context += f"Soru ({question_data['question_type']}): {question_data['question_text']}"
        
        return context
    except Exception as e:
        print(f"Soru metni çekilirken hata: {str(e)}")
        return None

def add_answer_feedback_column(connection):
    """application_answers tablosuna answer_feedback kolonu ekler (yoksa)"""
    try:
        cursor = connection.cursor()
        
        # Sütunun var olup olmadığını kontrol et
        cursor.execute("""
            SELECT COLUMN_NAME 
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = 'application_answers' 
            AND COLUMN_NAME = 'answer_feedback'
        """, (DB_CONFIG['database'],))
        
        result = cursor.fetchone()
        
        # Sütun yoksa oluştur
        if not result:
            print("answer_feedback sütunu oluşturuluyor...")
            cursor.execute("""
                ALTER TABLE application_answers
                ADD COLUMN answer_feedback TEXT COMMENT 'AI-generated feedback for the answer'
            """)
            connection.commit()
            print("answer_feedback sütunu başarıyla oluşturuldu")
        else:
            print("answer_feedback sütunu zaten mevcut")
        
        cursor.close()
        return True
    except Exception as e:
        print(f"answer_feedback sütunu oluşturulurken hata: {str(e)}")
        return False

def clean_text_for_json(text):
    """Metindeki JSON işlemeyi engelleyebilecek karakterleri temizler"""
    if not text:
        return text
        
    # Kontrol karakterlerini kaldır
    cleaned_text = re.sub(r'[\x00-\x1F\x7F]', '', text)
    return cleaned_text

def extract_json_safely(text):
    """Metinden JSON yapısını güvenli bir şekilde çıkarır"""
    # Önce metni temizle
    cleaned_text = clean_text_for_json(text)
    
    try:
        # Klasik JSON arama yöntemi
        json_start = cleaned_text.find('{')
        json_end = cleaned_text.rfind('}') + 1
        
        if json_start >= 0 and json_end > json_start:
            json_str = cleaned_text[json_start:json_end]
            # JSON doğrulama/düzeltme girişimi
            try:
                result = json.loads(json_str)
                return result
            except json.JSONDecodeError as e:
                print(f"JSON ayrıştırma hatası: {e}")
                print(f"Hatalı JSON: {json_str[:100]}...")
        
        # JSON yapılandırması bulunamazsa daha özgür bir arama yöntemi
        print("Standart JSON ayrıştırma başarısız. İçerik analiz ediliyor...")
        
        # Puan ve geri bildirim için metni analiz et
        score_pattern = r'["\']*score["\']*\s*:\s*(\d+)'
        feedback_pattern = r'["\']*feedback["\']*\s*:\s*["\']([^"\']+)["\']'
        
        score_match = re.search(score_pattern, cleaned_text)
        feedback_match = re.search(feedback_pattern, cleaned_text)
        
        score = 0
        if score_match:
            try:
                score = int(score_match.group(1))
                print(f"Regex ile puan bulundu: {score}")
            except:
                pass
        
        feedback = "Geri bildirim çıkarılamadı."
        if feedback_match:
            feedback = feedback_match.group(1)
            print(f"Regex ile geri bildirim bulundu: {feedback[:50]}...")
        
        # İkinci bir yöntem - satır bazlı arama
        if not score_match or not feedback_match:
            lines = cleaned_text.split('\n')
            for line in lines:
                if ("score" in line.lower() or "puan" in line.lower()) and ":" in line:
                    try:
                        score_text = line.split(":")[1].strip()
                        # Rakamları bul ve dönüştür
                        numbers = ''.join(filter(str.isdigit, score_text))
                        if numbers:
                            score = int(numbers)
                            print(f"Satırdan puan bulundu: {score}")
                    except:
                        pass
                if ("feedback" in line.lower() or "geri bildirim" in line.lower()) and ":" in line:
                    try:
                        feedback = line.split(":", 1)[1].strip()
                        print(f"Satırdan geri bildirim bulundu: {feedback[:50]}...")
                    except:
                        pass
        
        return {"score": score, "feedback": feedback}
    except Exception as e:
        print(f"JSON çıkarma hatası: {str(e)}")
        return {"score": 0, "feedback": f"Değerlendirme işlenirken hata oluştu: {str(e)}"}

def evaluate_answer(question_context, answer_text):
    """Cevabın kalitesini Google AI ile değerlendirir"""
    try:
        # Boş cevapları kontrol et
        if not answer_text or answer_text.strip() == "":
            return {"score": 0, "feedback": "Değerlendirilemedi: Cevap boş veya metin çıkarılamadı."}
            
        # Google Generative AI'yi yapılandır
        genai.configure(api_key=GOOGLE_API_KEY)
        
        # Mevcut modelleri listele ve seç
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
                model_name = gemini_models[0] if gemini_models else "gemini-pro"
            
            print(f"Seçilen model: {model_name}")
        except Exception as e:
            print(f"Model listesi alınamadı: {str(e)}")
            model_name = "gemini-pro"
        
        # Güvenlik ayarları
        safety_settings = {
            HarmCategory.HARM_CATEGORY_HARASSMENT: HarmBlockThreshold.BLOCK_NONE,
            HarmCategory.HARM_CATEGORY_HATE_SPEECH: HarmBlockThreshold.BLOCK_NONE,
            HarmCategory.HARM_CATEGORY_SEXUALLY_EXPLICIT: HarmBlockThreshold.BLOCK_NONE,
            HarmCategory.HARM_CATEGORY_DANGEROUS_CONTENT: HarmBlockThreshold.BLOCK_NONE,
        }
        
        # Modeli yapılandır
        model = genai.GenerativeModel(model_name=model_name, safety_settings=safety_settings)
        
        # Cevap değerlendirme promptu - JSON yapısını vurgular
        prompt = """
        Lütfen aşağıdaki açık uçlu soruya verilen cevabı değerlendir:
        
        SORU VE BAĞLAM:
        {question_context}
        
        ADAYIN CEVABI:
        {answer_text}
        
        Değerlendirme kriterleri:
        1. Doğruluk: Cevap doğru bilgiler içeriyor mu?
        2. Kapsamlılık: Cevap soruyu tam olarak ele alıyor mu?
        3. Bilgi derinliği: Cevap, konuya hakim olduğunu gösteriyor mu?
        4. Netlik ve ifade: Cevap net ve iyi ifade edilmiş mi?
        5. Özgünlük: Cevap özgün fikirler veya yaklaşımlar içeriyor mu?
        6. İş tanımına uygunluk: Cevap, iş pozisyonu gereklilikleriyle ne kadar örtüşüyor?
        
        Yanıtını SADECE aşağıdaki formatta bir JSON nesnesi olarak ver. Başka bir yorum veya açıklama ekleme:
        
        {{
          "score": (0-100 arası sayısal puan),
          "feedback": "detaylı değerlendirme ve geri bildirim metni"
        }}
        """.format(
            question_context=question_context,
            answer_text=answer_text
        )
        
        # AI'dan değerlendirme iste
        print("AI'dan değerlendirme isteniyor...")
        response = model.generate_content(prompt)
        
        # Cevabı işle
        response_text = response.text
        print(f"AI yanıtı alındı. Uzunluk: {len(response_text)} karakter")
        
        # Güvenli JSON çıkarma işlemi
        result = extract_json_safely(response_text)
        return result
    except Exception as e:
        print(f"AI değerlendirme hatası: {str(e)}")
        return {"score": 0, "feedback": f"Değerlendirme sırasında hata: {str(e)}"}

def process_answers():
    """Cevapları işler ve değerlendirir (hem dosya hem de metin cevaplar)"""
    try:
        # Veritabanına bağlan
        connection = mysql.connector.connect(**DB_CONFIG)
        
        # answer_feedback kolonu ekle (yoksa)
        if not add_answer_feedback_column(connection):
            print("answer_feedback kolonu eklenemiyor veya kontrol edilemiyor, işlem durduruluyor")
            return
        
        cursor = connection.cursor(dictionary=True)
        
        # 1. PDF DOSYASI İLE CEVAPLARI AL
        cursor.execute("""
            SELECT aa.id, aa.application_id, aa.question_id, aa.answer_file_path, aa.answer_score,
                   a.first_name, a.last_name, 'file' as answer_type
            FROM application_answers aa
            JOIN applications a ON aa.application_id = a.id
            WHERE aa.answer_file_path IS NOT NULL 
              AND aa.answer_file_path != ''
              AND aa.answer_score = 0
        """)
        
        file_answers = cursor.fetchall()
        
        # 2. METİN CEVAPLARI AL
        cursor.execute("""
            SELECT aa.id, aa.application_id, aa.question_id, aa.answer_text, aa.answer_score,
                   a.first_name, a.last_name, 'text' as answer_type
            FROM application_answers aa
            JOIN applications a ON aa.application_id = a.id
            WHERE aa.answer_text IS NOT NULL 
              AND aa.answer_text != ''
              AND aa.answer_file_path IS NULL
              AND aa.answer_score = 0
        """)
        
        text_answers = cursor.fetchall()
        
        # Tüm cevapları birleştir
        all_answers = file_answers + text_answers
        print(f"Toplam {len(all_answers)} adet cevap değerlendirilecek.")
        print(f"- {len(file_answers)} dosya cevabı")
        print(f"- {len(text_answers)} metin cevabı")
        
        for answer in all_answers:
            answer_id = answer['id']
            question_id = answer['question_id']
            answer_type = answer['answer_type']
            applicant_name = f"{answer['first_name']} {answer['last_name']}"
            
            print(f"\nCevap ID: {answer_id} işleniyor (Aday: {applicant_name})")
            print(f"Soru ID: {question_id}, Cevap Tipi: {answer_type}")
            
            # Cevap metnini al
            answer_text = None
            
            if answer_type == 'file':
                file_path = answer['answer_file_path']
                print(f"Dosya Yolu: {file_path}")
                answer_text = extract_text_from_pdf(file_path)
            else:  # text
                answer_text = answer['answer_text']
                print(f"Metin Cevap: {answer_text[:50]}..." if answer_text else "Metin boş!")
            
            if not answer_text:
                print(f"Cevap ID {answer_id} için içerik alınamadı, atlanıyor")
                continue
            
            # Soru metnini al
            question_context = get_question_text(connection, question_id)
            
            if not question_context:
                print(f"Soru ID {question_id} için soru metni alınamadı, atlanıyor")
                continue
            
            print("Soru ve cevap alındı, AI değerlendirmesi başlatılıyor...")
            
            # AI değerlendirmesi için gönder
            evaluation = evaluate_answer(question_context, answer_text)
            
            # Veritabanını güncelle
            score = evaluation.get('score', 0)
            feedback = evaluation.get('feedback', 'Geri bildirim sağlanamadı')
            
            print(f"Değerlendirme alındı - Puan: {score}")
            print(f"Geri Bildirim: {feedback[:150]}... (kısaltılmış)")
            
            # Veritabanını hem puan hem geri bildirimle güncelle
            try:
                cursor.execute("""
                    UPDATE application_answers 
                    SET answer_score = %s, answer_feedback = %s
                    WHERE id = %s
                """, (score, feedback, answer_id))
                
                connection.commit()
                print(f"Cevap ID {answer_id} için veritabanı güncellendi (puan ve geri bildirim)")
            except Exception as e:
                print(f"Veritabanı güncelleme hatası: {str(e)}")
            
            # API'yi fazla yüklememek için küçük bir gecikme ekle
            time.sleep(1)
            
        cursor.close()
        connection.close()
        print("\nİşlem tamamlandı!")
        
    except Exception as e:
        print(f"Cevapları işlerken hata: {str(e)}")

if __name__ == "__main__":
    try:
        process_answers()
    except Exception as e:
        print(f"Genel hata: {str(e)}")
    finally:
        end_time = datetime.datetime.now()
        print(f"Cevap Değerlendirme Sistemi tamamlandı: {end_time}")
        print(f"Toplam süre: {end_time - start_time}")