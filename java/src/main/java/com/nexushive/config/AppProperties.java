package com.nexushive.config;

import org.springframework.boot.context.properties.ConfigurationProperties;

@ConfigurationProperties(prefix = "nexushive")
public class AppProperties {
    private String tablePrefix = "nz_";
    private String tokenKey = "";
    private long tokenKeepSeconds = 259200;
    private long refreshKeepSeconds = 2592000;
    private String publicDir = "../public";
    private Mqtt mqtt = new Mqtt();
    private Storage storage = new Storage();
    private Dji dji = new Dji();
    private Agora agora = new Agora();
    private Zlm zlm = new Zlm();
    private Dashscope dashscope = new Dashscope();

    public String table(String name) {
        return tablePrefix + name;
    }

    public String getTablePrefix() { return tablePrefix; }
    public void setTablePrefix(String tablePrefix) { this.tablePrefix = tablePrefix; }
    public String getTokenKey() { return tokenKey; }
    public void setTokenKey(String tokenKey) { this.tokenKey = tokenKey; }
    public long getTokenKeepSeconds() { return tokenKeepSeconds; }
    public void setTokenKeepSeconds(long tokenKeepSeconds) { this.tokenKeepSeconds = tokenKeepSeconds; }
    public long getRefreshKeepSeconds() { return refreshKeepSeconds; }
    public void setRefreshKeepSeconds(long refreshKeepSeconds) { this.refreshKeepSeconds = refreshKeepSeconds; }
    public String getPublicDir() { return publicDir; }
    public void setPublicDir(String publicDir) { this.publicDir = publicDir; }
    public Mqtt getMqtt() { return mqtt; }
    public void setMqtt(Mqtt mqtt) { this.mqtt = mqtt; }
    public Storage getStorage() { return storage; }
    public void setStorage(Storage storage) { this.storage = storage; }
    public Dji getDji() { return dji; }
    public void setDji(Dji dji) { this.dji = dji; }
    public Agora getAgora() { return agora; }
    public void setAgora(Agora agora) { this.agora = agora; }
    public Zlm getZlm() { return zlm; }
    public void setZlm(Zlm zlm) { this.zlm = zlm; }
    public Dashscope getDashscope() { return dashscope; }
    public void setDashscope(Dashscope dashscope) { this.dashscope = dashscope; }

    public static class Mqtt {
        private String uri = "tcp://127.0.0.1:1883";
        private String username = "";
        private String password = "";
        private String clientId = "";
        public String getUri() { return uri; }
        public void setUri(String uri) { this.uri = uri; }
        public String getUsername() { return username; }
        public void setUsername(String username) { this.username = username; }
        public String getPassword() { return password; }
        public void setPassword(String password) { this.password = password; }
        public String getClientId() { return clientId; }
        public void setClientId(String clientId) { this.clientId = clientId; }
    }

    public static class Storage {
        private String provider = "ali";
        private String bucket = "";
        private String endpoint = "";
        private String region = "cn-chengdu";
        private String cdnUrl = "";
        private String stsUrl = "https://sts.aliyuncs.com";
        private String accessKeyId = "";
        private String accessKeySecret = "";
        private String roleArn = "";
        private String roleSessionName = "nexushive";
        private int stsDuration = 3599;
        private String minioAccessKey = "";
        private String minioSecretKey = "";
        public String getProvider() { return provider; }
        public void setProvider(String provider) { this.provider = provider; }
        public String getBucket() { return bucket; }
        public void setBucket(String bucket) { this.bucket = bucket; }
        public String getEndpoint() { return endpoint; }
        public void setEndpoint(String endpoint) { this.endpoint = endpoint; }
        public String getRegion() { return region; }
        public void setRegion(String region) { this.region = region; }
        public String getCdnUrl() { return cdnUrl; }
        public void setCdnUrl(String cdnUrl) { this.cdnUrl = cdnUrl; }
        public String getStsUrl() { return stsUrl; }
        public void setStsUrl(String stsUrl) { this.stsUrl = stsUrl; }
        public String getAccessKeyId() { return accessKeyId; }
        public void setAccessKeyId(String accessKeyId) { this.accessKeyId = accessKeyId; }
        public String getAccessKeySecret() { return accessKeySecret; }
        public void setAccessKeySecret(String accessKeySecret) { this.accessKeySecret = accessKeySecret; }
        public String getRoleArn() { return roleArn; }
        public void setRoleArn(String roleArn) { this.roleArn = roleArn; }
        public String getRoleSessionName() { return roleSessionName; }
        public void setRoleSessionName(String roleSessionName) { this.roleSessionName = roleSessionName; }
        public int getStsDuration() { return stsDuration; }
        public void setStsDuration(int stsDuration) { this.stsDuration = stsDuration; }
        public String getMinioAccessKey() { return minioAccessKey; }
        public void setMinioAccessKey(String minioAccessKey) { this.minioAccessKey = minioAccessKey; }
        public String getMinioSecretKey() { return minioSecretKey; }
        public void setMinioSecretKey(String minioSecretKey) { this.minioSecretKey = minioSecretKey; }
        public boolean minio() { return "minio".equalsIgnoreCase(provider); }
    }

    public static class Dji {
        private String ntpHost = "ntp.aliyun.com";
        private int ntpPort = 123;
        private String appId = "";
        private String appKey = "";
        private String appLicense = "";
        public String getNtpHost() { return ntpHost; }
        public void setNtpHost(String ntpHost) { this.ntpHost = ntpHost; }
        public int getNtpPort() { return ntpPort; }
        public void setNtpPort(int ntpPort) { this.ntpPort = ntpPort; }
        public String getAppId() { return appId; }
        public void setAppId(String appId) { this.appId = appId; }
        public String getAppKey() { return appKey; }
        public void setAppKey(String appKey) { this.appKey = appKey; }
        public String getAppLicense() { return appLicense; }
        public void setAppLicense(String appLicense) { this.appLicense = appLicense; }
    }

    public static class Agora {
        private String tokenUrl = "";
        public String getTokenUrl() { return tokenUrl; }
        public void setTokenUrl(String tokenUrl) { this.tokenUrl = tokenUrl; }
    }

    public static class Zlm {
        private String apiServer = "";
        private String apiSecret = "";
        private String rtmpUrl = "";
        private String flvUrl = "";
        private String hlsUrl = "";
        private String apiSchema = "rtmp";
        private String app = "live";
        private String rtmpSecret = "";
        public String getApiServer() { return apiServer; }
        public void setApiServer(String apiServer) { this.apiServer = apiServer; }
        public String getApiSecret() { return apiSecret; }
        public void setApiSecret(String apiSecret) { this.apiSecret = apiSecret; }
        public String getRtmpUrl() { return rtmpUrl; }
        public void setRtmpUrl(String rtmpUrl) { this.rtmpUrl = rtmpUrl; }
        public String getFlvUrl() { return flvUrl; }
        public void setFlvUrl(String flvUrl) { this.flvUrl = flvUrl; }
        public String getHlsUrl() { return hlsUrl; }
        public void setHlsUrl(String hlsUrl) { this.hlsUrl = hlsUrl; }
        public String getApiSchema() { return apiSchema; }
        public void setApiSchema(String apiSchema) { this.apiSchema = apiSchema; }
        public String getApp() { return app; }
        public void setApp(String app) { this.app = app; }
        public String getRtmpSecret() { return rtmpSecret; }
        public void setRtmpSecret(String rtmpSecret) { this.rtmpSecret = rtmpSecret; }
    }

    public static class Dashscope {
        private String apiKey = "";
        public String getApiKey() { return apiKey; }
        public void setApiKey(String apiKey) { this.apiKey = apiKey; }
    }
}
