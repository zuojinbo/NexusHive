package com.nexushive.security;

import org.springframework.security.crypto.bcrypt.BCryptPasswordEncoder;

import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;

/** PHP password_hash(PASSWORD_DEFAULT) plus legacy md5(md5(password).salt). */
public final class PasswordVerifier {
    private final BCryptPasswordEncoder bcrypt = new BCryptPasswordEncoder();

    public boolean verify(String raw, String hash, String salt) {
        if (raw == null || hash == null || hash.isEmpty()) {
            return false;
        }
        if (hash.startsWith("$")) {
            String normalized = hash.replace("$2y$", "$2a$").replace("$2b$", "$2a$");
            return bcrypt.matches(raw, normalized);
        }
        String legacy = md5(md5(raw) + (salt == null ? "" : salt));
        return legacy.equalsIgnoreCase(hash);
    }

    public String hash(String raw) {
        String encoded = bcrypt.encode(raw);
        return encoded.replace("$2a$", "$2y$");
    }

    private static String md5(String value) {
        try {
            MessageDigest digest = MessageDigest.getInstance("MD5");
            byte[] bytes = digest.digest(value.getBytes(StandardCharsets.UTF_8));
            StringBuilder sb = new StringBuilder(bytes.length * 2);
            for (byte b : bytes) {
                sb.append(String.format("%02x", b));
            }
            return sb.toString();
        } catch (Exception e) {
            throw new IllegalStateException(e);
        }
    }
}
