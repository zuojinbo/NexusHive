package com.nexushive.media;

import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;
import com.nexushive.common.ApiException;
import com.nexushive.config.AppProperties;
import org.springframework.stereotype.Service;

import java.net.URI;
import java.net.URLEncoder;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.nio.charset.StandardCharsets;
import java.time.Duration;
import java.time.LocalDateTime;
import java.time.format.DateTimeFormatter;
import java.util.ArrayList;
import java.util.Base64;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

@Service
public class MediaHttp {
    private final AppProperties props;
    private final ObjectMapper json;
    private final HttpClient http = HttpClient.newBuilder().connectTimeout(Duration.ofSeconds(8)).build();

    public MediaHttp(AppProperties props, ObjectMapper json) {
        this.props = props;
        this.json = json;
    }

    public Map<String, Object> querySecret() {
        return Map.of("secret", props.getZlm().getRtmpSecret() == null ? "" : props.getZlm().getRtmpSecret(), "update", "", "source", "config");
    }

    public Map<String, Object> streamStatus(String streamKey) {
        if (streamKey == null || streamKey.isBlank()) throw new ApiException("流名称不能为空");
        requireZlm();
        JsonNode data = zlm("/index/api/getMediaList", Map.of("schema", props.getZlm().getApiSchema(), "stream", streamKey)).path("data");
        if (!data.isArray() || data.isEmpty()) {
            return Map.of("exists", false, "message", "流不存在或未推流");
        }
        JsonNode stream = data.get(0);
        String app = stream.path("app").asText(props.getZlm().getApp());
        Map<String, Object> out = new LinkedHashMap<>();
        out.put("exists", true);
        out.put("stream", json.convertValue(stream, Map.class));
        out.put("playUrls", play(streamKey, app));
        return out;
    }

    public Map<String, Object> kick(String streamKey) {
        if (streamKey == null || streamKey.isBlank()) throw new ApiException("流名称不能为空");
        requireZlm();
        JsonNode result = zlm("/index/api/close_streams", Map.of(
                "schema", props.getZlm().getApiSchema(),
                "vhost", "__defaultVhost__",
                "app", props.getZlm().getApp(),
                "stream", streamKey,
                "force", "1"));
        if (result.path("count_closed").asInt(0) < 1 && result.path("data").path("count_closed").asInt(0) < 1) {
            throw new ApiException(result.path("msg").asText("关闭流失败"));
        }
        return Map.of("streamKey", streamKey, "message", "流已踢出");
    }

    public Map<String, Object> streamList() {
        requireZlm();
        JsonNode data = zlm("/index/api/getMediaList", Map.of("schema", props.getZlm().getApiSchema())).path("data");
        List<Map<String, Object>> streams = new ArrayList<>();
        if (data.isArray()) {
            for (JsonNode stream : data) {
                String name = stream.path("stream").asText("");
                String app = stream.path("app").asText(props.getZlm().getApp());
                Map<String, Object> item = new LinkedHashMap<>();
                item.put("name", name);
                item.put("app", app);
                item.put("live_ms", stream.path("aliveSecond").asLong(0) * 1000);
                item.put("clients", stream.path("totalReaderCount").asInt(stream.path("readerCount").asInt(0)));
                item.put("send_bytes", stream.path("bytesSpeed").asLong(0));
                item.put("playUrls", play(name, app));
                streams.add(item);
            }
        }
        return Map.of("streams", streams, "total", streams.size());
    }

    public Map<String, Object> agoraToken(Map<String, Object> body) {
        String channel = str(body.get("channelName"));
        String uid = str(body.get("uid"));
        if (channel.isBlank()) throw new ApiException("频道名称不能为空");
        if (uid.isBlank()) throw new ApiException("用户ID不能为空");
        String url = props.getAgora().getTokenUrl();
        if (url == null || url.isBlank()) throw new ApiException("声网服务未配置");
        Map<String, Object> payload = new LinkedHashMap<>();
        payload.put("channelName", channel);
        payload.put("uid", uid);
        payload.put("tokenExpireTs", num(body.get("tokenExpireTs"), 3600));
        payload.put("privilegeExpireTs", num(body.get("privilegeExpireTs"), 3600));
        payload.put("serviceRtc", body.get("serviceRtc") == null ? Map.of("enable", true, "role", 1) : body.get("serviceRtc"));
        JsonNode result = postJson(url + (url.contains("?") ? "&" : "?") + "server=1", payload);
        String token = result.path("data").path("token").asText("");
        if (token.isBlank()) throw new ApiException(result.path("msg").asText("获取Token失败"));
        return Map.of("token", token, "channel", channel, "uid", uid);
    }

    public Map<String, Object> videoTest() {
        return Map.of("time", LocalDateTime.now().format(DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss")), "runtime", "OpenJDK 17");
    }

    public Map<String, Object> analyzeFrame(Map<String, Object> body) {
        String image = str(body.get("image"));
        if (image.isBlank()) throw new ApiException("image参数不能为空");
        String base64 = image.replaceFirst("^data:image/[^;]+;base64,", "").replaceAll("\\s", "");
        byte[] bytes;
        try {
            bytes = Base64.getDecoder().decode(base64);
        } catch (IllegalArgumentException e) {
            throw new ApiException("base64解码失败或数据太小");
        }
        if (bytes.length < 100) throw new ApiException("base64解码失败或数据太小");
        String key = props.getDashscope().getApiKey();
        if (key == null || key.isBlank()) throw new ApiException("API Key未配置，请在环境变量 DASHSCOPE_API_KEY 中配置");
        String text = str(body.get("text"));
        if (text.isBlank()) text = "请描述画面中的内容";
        Map<String, Object> payload = Map.of(
                "model", "qwen-vl-plus",
                "input", Map.of("messages", List.of(Map.of(
                        "role", "user",
                        "content", List.of(
                                Map.of("image", "data:image/jpeg;base64," + base64),
                                Map.of("text", text))))));
        JsonNode result = postJsonAuth("https://dashscope.aliyuncs.com/api/v1/services/aigc/multimodal-generation/generation", payload, key);
        String content = result.path("output").path("choices").path(0).path("message").path("content").path(0).path("text").asText("");
        if (content.isBlank()) content = result.path("output").path("text").asText(result.toString());
        return Map.of("text", content, "model", "qwen-vl-plus");
    }

    private void requireZlm() {
        if (props.getZlm().getApiServer() == null || props.getZlm().getApiServer().isBlank()) {
            throw new ApiException("ZLMediaKit 未配置");
        }
    }

    private JsonNode zlm(String path, Map<String, String> query) {
        try {
            StringBuilder url = new StringBuilder(props.getZlm().getApiServer().replaceAll("/$", "")).append(path).append("?secret=")
                    .append(URLEncoder.encode(props.getZlm().getApiSecret() == null ? "" : props.getZlm().getApiSecret(), StandardCharsets.UTF_8));
            for (Map.Entry<String, String> entry : query.entrySet()) {
                url.append('&').append(URLEncoder.encode(entry.getKey(), StandardCharsets.UTF_8))
                        .append('=').append(URLEncoder.encode(entry.getValue(), StandardCharsets.UTF_8));
            }
            HttpRequest request = HttpRequest.newBuilder(URI.create(url.toString())).timeout(Duration.ofSeconds(10)).GET().build();
            HttpResponse<String> response = http.send(request, HttpResponse.BodyHandlers.ofString());
            JsonNode node = json.readTree(response.body());
            if (node.path("code").asInt(-1) != 0) {
                throw new ApiException(node.path("msg").asText("ZLMediaKit api request failed"));
            }
            return node;
        } catch (ApiException e) {
            throw e;
        } catch (Exception e) {
            throw new ApiException("请求 ZLMediaKit 服务失败: " + e.getMessage());
        }
    }

    private Map<String, String> play(String streamKey, String app) {
        String flv = props.getZlm().getFlvUrl() == null ? "" : props.getZlm().getFlvUrl().replaceAll("/$", "");
        String hls = props.getZlm().getHlsUrl() == null ? "" : props.getZlm().getHlsUrl().replaceAll("/$", "");
        String rtmp = props.getZlm().getRtmpUrl() == null ? "" : props.getZlm().getRtmpUrl().replaceAll("/$", "");
        return Map.of(
                "rtmp", rtmp.isBlank() ? "" : rtmp + "/" + streamKey,
                "flv", flv.isBlank() ? "" : flv + "/" + app + "/" + streamKey + ".live.flv",
                "hls", hls.isBlank() ? "" : hls + "/" + app + "/" + streamKey + "/hls.m3u8");
    }

    private JsonNode postJson(String url, Object payload) {
        try {
            HttpRequest request = HttpRequest.newBuilder(URI.create(url))
                    .timeout(Duration.ofSeconds(15))
                    .header("Content-Type", "application/json")
                    .POST(HttpRequest.BodyPublishers.ofString(json.writeValueAsString(payload)))
                    .build();
            HttpResponse<String> response = http.send(request, HttpResponse.BodyHandlers.ofString());
            return json.readTree(response.body());
        } catch (Exception e) {
            throw new ApiException("请求声网服务失败: " + e.getMessage());
        }
    }

    private JsonNode postJsonAuth(String url, Object payload, String key) {
        try {
            HttpRequest request = HttpRequest.newBuilder(URI.create(url))
                    .timeout(Duration.ofSeconds(30))
                    .header("Content-Type", "application/json")
                    .header("Authorization", "Bearer " + key)
                    .POST(HttpRequest.BodyPublishers.ofString(json.writeValueAsString(payload)))
                    .build();
            HttpResponse<String> response = http.send(request, HttpResponse.BodyHandlers.ofString());
            return json.readTree(response.body());
        } catch (Exception e) {
            throw new ApiException("系统错误: " + e.getMessage());
        }
    }

    private static String str(Object value) {
        return value == null ? "" : String.valueOf(value);
    }

    private static int num(Object value, int fallback) {
        if (value instanceof Number n) return n.intValue();
        try { return Integer.parseInt(str(value)); } catch (Exception e) { return fallback; }
    }
}
