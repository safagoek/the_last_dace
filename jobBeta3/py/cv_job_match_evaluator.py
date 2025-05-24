import mysql.connector
import os
import PyPDF2
import google.generativeai as genai
import json
import time

# Database configuration
DB_CONFIG = {
    "host": "localhost",
    "user": "root",  # replace with your MySQL username
    "password": "",  # replace with your MySQL password
    "database": "job_application_system_db"  # replace with your MySQL database name
}

# Google AI Studio configuration
GOOGLE_API_KEY = "YOUR_GOOGLE_AI_STUDIO_API_KEY"  # replace with your API key

# Base directory - change this to your web root directory path
# This is where your application's files are stored
BASE_DIRECTORY = "C:/xampp/htdocs"  # Change this! Örneğin: /var/www/html, C:/xampp/htdocs, etc.

def get_full_path(relative_path):
    """Convert a relative path to a full path based on the BASE_DIRECTORY."""
    # Remove any leading slash to ensure proper path joining
    if relative_path.startswith('/'):
        relative_path = relative_path[1:]
    
    return os.path.join(BASE_DIRECTORY, relative_path)

def extract_text_from_pdf(relative_path):
    """Extract text content from a PDF file."""
    try:
        # Convert relative path to full path
        full_path = get_full_path(relative_path)
        
        print(f"Attempting to read PDF from: {full_path}")
        
        # Check if file exists
        if not os.path.exists(full_path):
            print(f"Error: PDF file not found at {full_path}")
            return None
        
        text = ""
        with open(full_path, 'rb') as file:
            reader = PyPDF2.PdfReader(file)
            for page_num in range(len(reader.pages)):
                text += reader.pages[page_num].extract_text()
        return text
    except Exception as e:
        print(f"Error extracting text from {relative_path} (full path: {full_path}): {e}")
        return None

def get_job_description(connection, job_id):
    """Fetch job description from the database."""
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
            return None
        
        # Combine job details for a comprehensive description
        full_description = f"Job Title: {job['title']}\n"
        full_description += f"Location: {job['location']}\n"
        full_description += f"Description: {job['description']}"
        
        return full_description
    except Exception as e:
        print(f"Error fetching job description: {e}")
        return None

def evaluate_cv_job_match(cv_text, job_description):
    """
    Send CV text and job description to Google AI Studio to evaluate the match.
    """
    # Configure the AI model
    genai.configure(api_key=GOOGLE_API_KEY)
    model = genai.GenerativeModel('gemini-pro')
    
    # Prompt for CV-job match evaluation
    prompt = """
    Please evaluate how well this resume/CV matches the provided job description. 
    
    Job Description:
    {job_description}
    
    CV Content:
    {cv_text}
    
    Consider:
    1. Skills match: How well do the candidate's skills match the job requirements?
    2. Experience relevance: Is the candidate's experience relevant to the position?
    3. Education fit: Does the candidate's education background align with the job?
    4. Overall suitability: How suitable is this candidate for this specific position?
    
    Provide:
    1. A numerical score from 0 to 100 representing the overall match percentage
    2. Detailed feedback explaining the strengths, weaknesses, and why the candidate is or isn't a good match
    
    Format your response as a JSON object:
    {{
        "score": (numeric score between 0-100),
        "feedback": "detailed feedback text"
    }}
    """
    
    # Format the prompt with the actual content
    formatted_prompt = prompt.format(
        job_description=job_description,
        cv_text=cv_text
    )
    
    # Get completion from the model
    try:
        response = model.generate_content(formatted_prompt)
        
        # Extract JSON from the response
        response_text = response.text
        # Look for JSON structure in the response
        json_start = response_text.find('{')
        json_end = response_text.rfind('}') + 1
        
        if json_start >= 0 and json_end > json_start:
            json_str = response_text[json_start:json_end]
            result = json.loads(json_str)
            return result
        else:
            # If JSON parsing fails, try to extract score and feedback manually
            lines = response_text.split('\n')
            score = 0
            feedback = "No structured feedback provided. Raw response: " + response_text[:500]
            
            for line in lines:
                if "score" in line.lower() and ":" in line:
                    try:
                        score_text = line.split(":")[1].strip()
                        score = int(score_text.split()[0])  # Extract just the number
                    except:
                        pass
                if "feedback" in line.lower() and ":" in line:
                    feedback = line.split(":", 1)[1].strip()
            
            return {"score": score, "feedback": feedback}
    except Exception as e:
        print(f"Error with AI evaluation: {e}")
        return {"score": 0, "feedback": f"Error during evaluation: {str(e)}"}

def ensure_cv_feedback_column_exists(connection):
    """Check if cv_feedback column exists in applications table, create it if not."""
    try:
        cursor = connection.cursor()
        
        # Check if cv_feedback column exists
        cursor.execute("""
            SELECT COLUMN_NAME 
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = 'applications' 
            AND COLUMN_NAME = 'cv_feedback'
        """, (DB_CONFIG['database'],))
        
        result = cursor.fetchone()
        
        # If column doesn't exist, create it
        if not result:
            print("Creating cv_feedback column...")
            cursor.execute("""
                ALTER TABLE applications
                ADD COLUMN cv_feedback TEXT COMMENT 'AI-generated feedback on the CV-job match'
            """)
            connection.commit()
            print("cv_feedback column created successfully")
        
        cursor.close()
    except Exception as e:
        print(f"Error ensuring cv_feedback column exists: {e}")
        raise

def process_applications():
    """Process applications with cv_score = 0, evaluating CV against job description."""
    try:
        # Connect to the database
        connection = mysql.connector.connect(**DB_CONFIG)
        cursor = connection.cursor(dictionary=True)
        
        # Ensure cv_feedback column exists
        ensure_cv_feedback_column_exists(connection)
        
        # Get applications with cv_score = 0
        cursor.execute("""
            SELECT a.id, a.cv_path, a.job_id, j.title as job_title
            FROM applications a
            JOIN jobs j ON a.job_id = j.id
            WHERE a.cv_score = 0
        """)
        
        applications = cursor.fetchall()
        print(f"Found {len(applications)} applications with cv_score = 0")
        
        for app in applications:
            app_id = app['id']
            cv_path = app['cv_path']
            job_id = app['job_id']
            job_title = app['job_title']
            
            print(f"\nProcessing application ID: {app_id}, CV path: {cv_path}")
            print(f"Job ID: {job_id}, Job Title: {job_title}")
            
            # Extract text from PDF
            cv_text = extract_text_from_pdf(cv_path)
            
            if not cv_text:
                print(f"Could not extract text from CV for application {app_id}")
                continue
            
            # Get job description
            job_description = get_job_description(connection, job_id)
            
            if not job_description:
                print(f"Could not fetch job description for job ID {job_id}")
                continue
            
            print("CV text and job description fetched, sending to AI for evaluation...")
            
            # Send to AI for evaluation of the match
            evaluation = evaluate_cv_job_match(cv_text, job_description)
            
            # Update the database with the score and feedback
            score = evaluation.get('score', 0)
            feedback = evaluation.get('feedback', 'No feedback provided')
            
            print(f"Received evaluation - Score: {score}")
            print(f"Feedback: {feedback[:150]}... (truncated)")
            
            cursor.execute("""
                UPDATE applications 
                SET cv_score = %s, cv_feedback = %s 
                WHERE id = %s
            """, (score, feedback, app_id))
            
            connection.commit()
            print(f"Updated database for application {app_id}")
            
            # Add a small delay to avoid overwhelming the API
            time.sleep(1)
            
        cursor.close()
        connection.close()
        print("\nProcessing complete!")
        
    except Exception as e:
        print(f"Error processing applications: {e}")

if __name__ == "__main__":
    print("Starting CV-Job match evaluation process...")
    process_applications()