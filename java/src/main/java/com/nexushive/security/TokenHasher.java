package com.nexushive.security;

import org.bouncycastle.jce.provider.BouncyCastleProvider;

import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;
import java.nio.charset.StandardCharsets;
import java.security.Security;

/** hash_hmac('ripemd160', token, key) hex, matching BuildAdmin token storage. */
public final class TokenHasher {
    static {
        if (Security.getProvider(BouncyCastleProvider.PROVIDER_NAME) == null) {
            Security.addProvider(new BouncyCastleProvider());
        }
    }

    private final String key;

    public TokenHasher(String key) {
        this.key = key == null ? "" : key;
    }

    public String hash(String token) {
        try {
            Mac mac = Mac.getInstance("HmacRIPEMD160", BouncyCastleProvider.PROVIDER_NAME);
            mac.init(new SecretKeySpec(key.getBytes(StandardCharsets.UTF_8), "HmacRIPEMD160"));
            byte[] out = mac.doFinal(token.getBytes(StandardCharsets.UTF_8));
            StringBuilder sb = new StringBuilder(out.length * 2);
            for (byte b : out) {
                sb.append(String.format("%02x", b));
            }
            return sb.toString();
        } catch (Exception e) {
            throw new IllegalStateException("RIPEMD160 token hash failed", e);
        }
    }
}
