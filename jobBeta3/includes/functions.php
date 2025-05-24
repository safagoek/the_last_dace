<?php
/**
 * Bir soru şablonunu bir iş ilanına kopyalar
 * @param PDO $db Veritabanı bağlantısı
 * @param int $templateId Şablon ID
 * @param int $jobId İş ilanı ID
 * @return bool İşlem başarılı mı?
 */
function applyTemplateToJob($db, $templateId, $jobId) {
    try {
        // Transaction başlatma - Eğer dışarıda bir transaction yoksa başlat
        $needsTransaction = !$db->inTransaction(); 
        if ($needsTransaction) {
            $db->beginTransaction();
        }
        
        // İş ilanını şablonla ilişkilendir
        $stmt = $db->prepare("UPDATE jobs SET template_id = :template_id WHERE id = :job_id");
        $stmt->bindParam(':template_id', $templateId, PDO::PARAM_INT);
        $stmt->bindParam(':job_id', $jobId, PDO::PARAM_INT);
        $stmt->execute();
        
        // Şablon sorularını al
        $stmt = $db->prepare("SELECT * FROM template_questions WHERE template_id = :template_id ORDER BY order_number");
        $stmt->bindParam(':template_id', $templateId, PDO::PARAM_INT);
        $stmt->execute();
        $templateQuestions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Her bir soruyu iş ilanına kopyala
        foreach ($templateQuestions as $templateQuestion) {
            // Soruyu ekle
            $stmt = $db->prepare("INSERT INTO questions (job_id, question_text, question_type, template_id) 
                                VALUES (:job_id, :question_text, :question_type, :template_id)");
            $stmt->bindParam(':job_id', $jobId, PDO::PARAM_INT);
            $stmt->bindParam(':question_text', $templateQuestion['question_text']);
            $stmt->bindParam(':question_type', $templateQuestion['question_type']);
            $stmt->bindParam(':template_id', $templateId, PDO::PARAM_INT);
            $stmt->execute();
            
            $newQuestionId = $db->lastInsertId();
            
            // Çoktan seçmeli soru ise şıklarını da kopyala
            if ($templateQuestion['question_type'] == 'multiple_choice') {
                // Şablon şıklarını al
                $stmt = $db->prepare("SELECT * FROM template_options WHERE template_question_id = :template_question_id");
                $stmt->bindParam(':template_question_id', $templateQuestion['id'], PDO::PARAM_INT);
                $stmt->execute();
                $templateOptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Şıkları kopyala
                foreach ($templateOptions as $templateOption) {
                    $stmt = $db->prepare("INSERT INTO options (question_id, option_text, is_correct) 
                                        VALUES (:question_id, :option_text, :is_correct)");
                    $stmt->bindParam(':question_id', $newQuestionId, PDO::PARAM_INT);
                    $stmt->bindParam(':option_text', $templateOption['option_text']);
                    $stmt->bindParam(':is_correct', $templateOption['is_correct'], PDO::PARAM_BOOL);
                    $stmt->execute();
                }
            }
        }
        
        // Eğer bu fonksiyon transaction başlattıysa, commit yap
        if ($needsTransaction) {
            $db->commit();
        }
        
        return true;
    } catch (Exception $e) {
        // Eğer bu fonksiyon transaction başlattıysa ve hata olduysa rollback yap
        if (isset($needsTransaction) && $needsTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Template application error: " . $e->getMessage());
        return false;
    }
}
?>