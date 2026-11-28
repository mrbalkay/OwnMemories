-- 011: Fotoğraftaki yazı (OCR). ownmemories-ml dizinleyicisi türetilmiş önizlemeden (2048 px) okuduğu yazıyı yazar.
--   text: tanınan satırlar ("\n" ile), yazı yoksa ''. folded: arama anahtarı — Türkçe duyarlı küçük harf, aksansız,
--   yalnız [a-z0-9] sözcükler, başta/sonda birer boşluk (" elektrik faturasi "); PHP ocr_fold() = Python ocr.fold().
--   Arama (web/includes/ocr.php): sözcük başı eşleşmeler FULLTEXT ile (MATCH … AGAINST('+fatura*' IN BOOLEAN MODE);
--   katlanmış metin ASCII olduğundan Türkçe ekler/harfler sorun değil: "faturası" → "faturasi"). Sözcük içi eşleşme
--   ("köfte" ⊂ "çiğköfte"; MariaDB'de ngram ayrıştırıcı yok) ve kısa/durak sözcükler kullanıcının yazılı satırlarında
--   (words > 0) LIKE taramasıyla; folded ascii_bin olduğundan bayt karşılaştırması (100 bin fotoğraf / 30 bin yazılı ≈ 0.1 sn).
--   engine: model + son işleme sürümü; değişince dizinleyici yeniden okur. Yazı yoksa da satır yazılır (tarandı).
CREATE TABLE IF NOT EXISTS asset_text (
  asset_id CHAR(36) NOT NULL PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  text MEDIUMTEXT NOT NULL,
  folded MEDIUMTEXT CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  lang VARCHAR(8) NULL,
  words INT UNSIGNED NOT NULL DEFAULT 0,
  engine VARCHAR(40) NOT NULL,
  computed_at DATETIME NOT NULL,
  KEY ix_at_user_words (user_id, words),
  KEY ix_at_engine (engine),
  FULLTEXT KEY ft_at_folded (folded)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
