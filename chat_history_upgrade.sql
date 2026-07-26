-- Run in phpMyAdmin → SQL tab

-- Persists AI Assistant chat turns so the last 5 days of conversation show up
-- again when the chat page is reopened. Rows older than 5 days are purged
-- automatically each time chat.php loads (see chat.php).
CREATE TABLE IF NOT EXISTS chat_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    role ENUM('user','assistant') NOT NULL,
    content MEDIUMTEXT NOT NULL,
    link VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id, created_at)
);
