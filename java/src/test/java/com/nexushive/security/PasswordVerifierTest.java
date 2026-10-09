package com.nexushive.security;

import org.junit.jupiter.api.Test;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertTrue;

class PasswordVerifierTest {
    private final PasswordVerifier passwords = new PasswordVerifier();

    @Test
    void verifiesPhpBcryptAndLegacyMd5() {
        String hash = "$2y$05$6viJkpu3gOJ3domlEDtU6.QPdYVrg35lgh80kiAmz2EcnPwCDDO62";
        assertTrue(passwords.verify("NexusHive@123", hash, ""));
        assertFalse(passwords.verify("wrong-password", hash, ""));
        assertTrue(passwords.verify("secret", "d7e6bd00c207dc09469cfa5b59e65b47", "x"));
        assertTrue(passwords.hash("NexusHive@123").startsWith("$2y$"));
    }

    @Test
    void tokenHashIsStableRipemd160() {
        TokenHasher hasher = new TokenHasher("Mi1sG8xQ9oHE5Taru73w24LKgkY6JIFt");
        String hash = hasher.hash("demo-token");
        assertEquals(40, hash.length());
        assertEquals(hash, hasher.hash("demo-token"));
        assertFalse(hash.equals(new TokenHasher("other").hash("demo-token")));
    }
}
