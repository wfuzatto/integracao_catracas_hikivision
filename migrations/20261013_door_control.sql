-- Controle de passagem livre por catraca/sentido e trilha de auditoria.
-- O estado fisico informado pela OpenAPI tem precedencia sobre o registro local,
-- exceto nos primeiros 20 segundos apos um comando aceito.
CREATE TABLE IF NOT EXISTS door_control_states (
  door_index_code VARCHAR(64) NOT NULL,
  direction TINYINT UNSIGNED NOT NULL,
  free_passage TINYINT(1) NOT NULL DEFAULT 0,
  last_action_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (door_index_code, direction)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS door_control_audit (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  door_index_code VARCHAR(64) NOT NULL,
  door_name VARCHAR(255) NOT NULL,
  direction TINYINT UNSIGNED NOT NULL,
  action VARCHAR(12) NOT NULL,
  result VARCHAR(12) NOT NULL,
  client_ip VARCHAR(45) NOT NULL,
  message VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_door_created (door_index_code, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
