<?php

namespace updater;

use RuntimeException;

/**
 * 签名验证器
 * 
 * 用于验证下载的更新包完整性和真实性
 */
class SignatureVerifier
{
    /**
     * 公钥内容
     * @var string
     */
    protected string $publicKey = '';
    
    /**
     * 验证文件完整性和签名
     * 
     * @param string $filePath  文件路径
     * @param string $hash      预期的 SHA256 哈希值
     * @param string $signature Base64 编码的 RSA 签名
     * @return bool
     * @throws RuntimeException
     */
    public function verify(string $filePath, string $hash, string $signature): bool
    {
        // 1. 验证文件是否存在
        if (!file_exists($filePath)) {
            throw new RuntimeException('文件不存在: ' . $filePath);
        }
        
        // 2. 验证文件哈希
        $actualHash = hash_file('sha256', $filePath);
        if (!hash_equals($hash, $actualHash)) {
            throw new RuntimeException('文件哈希验证失败，文件可能已损坏');
        }
        
        // 3. 验证 RSA 签名
        if (empty($this->publicKey)) {
            throw new RuntimeException('公钥未设置');
        }
        
        $publicKey = openssl_pkey_get_public($this->publicKey);
        if ($publicKey === false) {
            throw new RuntimeException('公钥加载失败: ' . openssl_error_string());
        }
        
        $decodedSignature = base64_decode($signature);
        if ($decodedSignature === false) {
            throw new RuntimeException('签名解码失败');
        }
        
        $result = openssl_verify($hash, $decodedSignature, $publicKey, OPENSSL_ALGO_SHA256);
        
        if ($result === 1) {
            return true;
        } elseif ($result === 0) {
            throw new RuntimeException('签名验证失败，更新包可能被篡改');
        } else {
            throw new RuntimeException('签名验证错误: ' . openssl_error_string());
        }
    }
    
    /**
     * 设置公钥
     * 
     * @param string $publicKey PEM 格式的公钥内容
     * @return void
     */
    public function setPublicKey(string $publicKey): void
    {
        $this->publicKey = $publicKey;
    }
    
    /**
     * 从文件加载公钥
     * 
     * @param string $path 公钥文件路径
     * @return void
     * @throws RuntimeException
     */
    public function loadPublicKeyFromFile(string $path): void
    {
        if (!file_exists($path)) {
            throw new RuntimeException("公钥文件不存在: {$path}");
        }
        
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("无法读取公钥文件: {$path}");
        }
        
        // 验证公钥格式是否有效
        $key = @openssl_pkey_get_public($content);
        if ($key === false) {
            throw new RuntimeException('公钥加载失败: ' . openssl_error_string());
        }
        
        $this->publicKey = $content;
    }
    
    /**
     * 获取当前公钥
     * 
     * @return string
     */
    public function getPublicKey(): string
    {
        return $this->publicKey;
    }
    
    /**
     * 仅验证签名（不验证文件哈希）
     * 
     * @param string $hash      数据的哈希值
     * @param string $signature Base64 编码的 RSA 签名
     * @return bool
     * @throws RuntimeException
     */
    public function verifySignatureOnly(string $hash, string $signature): bool
    {
        if (empty($this->publicKey)) {
            throw new RuntimeException('公钥未设置');
        }
        
        $publicKey = openssl_pkey_get_public($this->publicKey);
        if ($publicKey === false) {
            throw new RuntimeException('公钥加载失败: ' . openssl_error_string());
        }
        
        $decodedSignature = base64_decode($signature);
        if ($decodedSignature === false) {
            throw new RuntimeException('签名解码失败');
        }
        
        $result = openssl_verify($hash, $decodedSignature, $publicKey, OPENSSL_ALGO_SHA256);
        
        if ($result === 1) {
            return true;
        } elseif ($result === 0) {
            throw new RuntimeException('签名验证失败，更新包可能被篡改');
        } else {
            throw new RuntimeException('签名验证错误: ' . openssl_error_string());
        }
    }
}
