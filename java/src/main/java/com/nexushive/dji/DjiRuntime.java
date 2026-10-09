package com.nexushive.dji;

import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;
import com.nexushive.config.AppProperties;
import com.nexushive.db.SchemaMeta;
import jakarta.annotation.PreDestroy;
import org.eclipse.paho.client.mqttv3.MqttClient;
import org.eclipse.paho.client.mqttv3.MqttConnectOptions;
import org.eclipse.paho.client.mqttv3.MqttMessage;
import org.eclipse.paho.client.mqttv3.persist.MemoryPersistence;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.jdbc.core.JdbcTemplate;
import org.springframework.scheduling.annotation.Scheduled;
import org.springframework.stereotype.Service;

import java.nio.charset.StandardCharsets;
import java.time.Instant;
import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.UUID;
import java.util.concurrent.ConcurrentHashMap;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.atomic.AtomicBoolean;

@Service
public class DjiRuntime {
    private static final Logger log = LoggerFactory.getLogger(DjiRuntime.class);
    private final AppProperties props;
    private final JdbcTemplate jdbc;
    private final SchemaMeta schema;
    private final ObjectMapper json;
    private final AtomicBoolean connected = new AtomicBoolean(false);
    private final AtomicBoolean stopping = new AtomicBoolean(false);
    private final List<String> logs = new ArrayList<>();
    private final Map<String, Object> osdCache = new ConcurrentHashMap<>();
    private final ExecutorService connector = Executors.newSingleThreadExecutor(r -> {
        Thread thread = new Thread(r, "nexushive-mqtt");
        thread.setDaemon(true);
        return thread;
    });
    private volatile MqttClient client;
    private volatile String lastError = "";

    public DjiRuntime(AppProperties props, JdbcTemplate jdbc, SchemaMeta schema, ObjectMapper json) {
        this.props = props;
        this.jdbc = jdbc;
        this.schema = schema;
        this.json = json;
        connector.submit(this::connectLoop);
    }

    private void connectLoop() {
        while (!stopping.get() && !Thread.currentThread().isInterrupted()) {
            try {
                if (client == null || !client.isConnected()) {
                    connectOnce();
                }
                Thread.sleep(5000);
            } catch (InterruptedException e) {
                Thread.currentThread().interrupt();
                return;
            } catch (Exception e) {
                lastError = e.getMessage() == null ? e.getClass().getSimpleName() : e.getMessage();
                connected.set(false);
                log.warn("MQTT 未连接，应用继续运行: {}", lastError);
                try { Thread.sleep(5000); } catch (InterruptedException ie) { Thread.currentThread().interrupt(); return; }
            }
        }
    }

    private void connectOnce() throws Exception {
        String clientId = props.getMqtt().getClientId();
        if (clientId == null || clientId.isBlank()) clientId = "nexushive-" + UUID.randomUUID();
        MqttClient mqtt = new MqttClient(props.getMqtt().getUri(), clientId, new MemoryPersistence());
        MqttConnectOptions options = new MqttConnectOptions();
        options.setAutomaticReconnect(true);
        options.setCleanSession(true);
        options.setConnectionTimeout(8);
        options.setKeepAliveInterval(60);
        if (props.getMqtt().getUsername() != null && !props.getMqtt().getUsername().isBlank()) {
            options.setUserName(props.getMqtt().getUsername());
            options.setPassword(props.getMqtt().getPassword() == null ? new char[0] : props.getMqtt().getPassword().toCharArray());
        }
        mqtt.setCallback(new org.eclipse.paho.client.mqttv3.MqttCallback() {
            @Override public void connectionLost(Throwable cause) {
                connected.set(false);
                lastError = cause == null ? "connection lost" : String.valueOf(cause.getMessage());
            }
            @Override public void messageArrived(String topic, MqttMessage message) {
                onMessage(topic, new String(message.getPayload(), StandardCharsets.UTF_8));
            }
            @Override public void deliveryComplete(org.eclipse.paho.client.mqttv3.IMqttDeliveryToken token) {}
        });
        mqtt.connect(options);
        this.client = mqtt;
        connected.set(true);
        lastError = "";
        note("MQTT connected " + props.getMqtt().getUri());
        mqtt.subscribe("system/equipment/set/equipment_add");
        List<Map<String, Object>> devices = jdbc.queryForList("SELECT sn FROM `" + props.table("equipment") + "` WHERE sn IS NOT NULL AND sn<>''");
        for (Map<String, Object> device : devices) {
            subscribeSn(String.valueOf(device.get("sn")));
        }
    }

    public void subscribeSn(String sn) {
        if (sn == null || sn.isBlank() || client == null || !client.isConnected()) return;
        String[] topics = {
                "thing/product/" + sn + "/events",
                "sys/product/" + sn + "/status",
                "thing/product/" + sn + "/osd",
                "thing/product/" + sn + "/requests",
                "thing/product/" + sn + "/services_reply",
                "thing/product/" + sn + "/flighttask_progress"
        };
        try {
            for (String topic : topics) client.subscribe(topic);
        } catch (Exception e) {
            log.warn("订阅失败 {}: {}", sn, e.getMessage());
        }
    }

    public boolean publish(Map<String, Object> message) {
        if (message == null || client == null || !client.isConnected()) return false;
        Object topic = message.get("topic");
        if (topic == null) return false;
        try {
            Map<String, Object> body = new LinkedHashMap<>(message);
            body.remove("topic");
            MqttMessage mqttMessage = new MqttMessage(json.writeValueAsBytes(body));
            mqttMessage.setQos(1);
            client.publish(String.valueOf(topic), mqttMessage);
            return true;
        } catch (Exception e) {
            log.warn("MQTT 发布失败: {}", e.getMessage());
            return false;
        }
    }

    public Map<String, Object> status() {
        Map<String, Object> map = new LinkedHashMap<>();
        map.put("connected", connected.get());
        map.put("uri", props.getMqtt().getUri());
        map.put("lastError", lastError);
        map.put("clientId", client == null ? "" : client.getClientId());
        return map;
    }

    public List<String> recentLogs() {
        synchronized (logs) { return List.copyOf(logs); }
    }

    public Object cacheGet(String key, Object fallback) {
        return osdCache.getOrDefault(key, fallback);
    }

    @Scheduled(fixedDelay = 5000)
    public void scanDueTasks() {
        if (!connected.get()) return;
        try {
            List<Map<String, Object>> tasks = jdbc.queryForList(
                    "SELECT * FROM `" + props.table("flighttask") + "` WHERE status='sent' AND execute_time IS NOT NULL AND execute_time<=?",
                    Instant.now().getEpochSecond());
            for (Map<String, Object> task : tasks) {
                executeTask(task);
            }
        } catch (Exception e) {
            log.debug("扫描待执行任务失败: {}", e.getMessage());
        }
    }

    public void executeTask(Map<String, Object> task) {
        Object equipmentId = task.get("equipment_id");
        if (equipmentId == null) return;
        List<Map<String, Object>> eqs = jdbc.queryForList("SELECT sn FROM `" + props.table("equipment") + "` WHERE id=?", equipmentId);
        if (eqs.isEmpty()) return;
        Map<String, Object> msg = base(String.valueOf(task.get("bid")), "flighttask_execute",
                "thing/product/" + eqs.get(0).get("sn") + "/services");
        msg.put("data", Map.of("flight_id", task.get("bid")));
        if (publish(msg)) {
            jdbc.update("UPDATE `" + props.table("flighttask") + "` SET status='in_progress', update_time=? WHERE id=?",
                    Instant.now().getEpochSecond(), task.get("id"));
        }
    }

    public Map<String, Object> buildPrepare(Map<String, Object> task, String sn) {
        boolean manual = "manual".equals(String.valueOf(task.get("flight_mode")));
        Map<String, Object> data = new LinkedHashMap<>();
        data.put("flight_id", task.get("bid"));
        data.put("task_type", toInt(task.get("task_type")));
        if (!manual && task.get("file_url") != null && !String.valueOf(task.get("file_url")).isBlank()) {
            String url = cdn() + task.get("file_url");
            data.put("file", Map.of("url", url, "fingerprint", task.getOrDefault("kmz_md5", "")));
        }
        Object execute = task.get("execute_time");
        long ms = Instant.now().toEpochMilli();
        if (execute instanceof Number n) {
            long v = n.longValue();
            ms = String.valueOf(v).length() <= 10 ? v * 1000 : v;
        }
        data.put("execute_time", ms);
        data.put("rth_altitude", toInt(task.getOrDefault("rth_altitude", 100)));
        data.put("rth_mode", toInt(task.getOrDefault("rth_mode", 1)));
        data.put("out_of_control_action", toInt(task.getOrDefault("out_of_control_action", 0)));
        data.put("exit_wayline_when_rc_lost", toInt(task.getOrDefault("exit_wayline_when_rc_lost", 0)));
        if (!manual) data.put("wayline_precision_type", toInt(task.getOrDefault("wayline_precision_type", 1)));
        Map<String, Object> msg = base(String.valueOf(task.get("bid")), "flighttask_prepare", "thing/product/" + sn + "/services");
        if (task.get("tid") != null) msg.put("tid", task.get("tid"));
        msg.put("data", data);
        return msg;
    }

    private void onMessage(String topic, String payload) {
        try {
            JsonNode root = json.readTree(payload);
            String[] parts = topic.split("/");
            if (parts.length < 4) return;
            String sn = parts.length >= 3 ? parts[2] : "";
            String kind = parts[parts.length - 1];
            if ("equipment_add".equals(kind)) {
                JsonNode newSn = root.get("new_sn");
                if (newSn != null) subscribeSn(newSn.asText());
                return;
            }
            Map<String, Object> body = json.convertValue(root, Map.class);
            body.put("sn", sn);
            switch (kind) {
                case "events" -> onEvents(body);
                case "requests" -> onRequests(body);
                case "services_reply" -> onServicesReply(body);
                case "osd" -> onOsd(body);
                case "status" -> onStatus(body);
                default -> {}
            }
        } catch (Exception e) {
            log.debug("MQTT 消息解析失败 {}: {}", topic, e.getMessage());
        }
    }

    @SuppressWarnings("unchecked")
    private void onEvents(Map<String, Object> param) {
        String method = String.valueOf(param.get("method"));
        if ("file_upload_callback".equals(method)) saveMedia(param);
        if ("hms".equals(method)) saveHms(param);
        if ("ota_progress".equals(method)) saveOta(param);
        if ("flighttask_progress".equals(method)) onProgress(param);
        if ("return_home_info".equals(method)) saveReturn(param);
        Object need = param.get("need_reply");
        if (need instanceof Number n && n.intValue() == 1) {
            reply(param, "thing/product/" + param.get("sn") + "/events_reply", Map.of("result", 0));
        }
    }

    @SuppressWarnings("unchecked")
    private void onRequests(Map<String, Object> param) {
        String method = String.valueOf(param.get("method"));
        String sn = String.valueOf(param.get("sn"));
        switch (method) {
            case "flighttask_resource_get" -> resourceReply(param);
            case "storage_config_get" -> storageReply(param);
            case "config" -> configReply(param);
            case "airport_bind_status" -> bindStatus(param);
            case "airport_organization_get" -> orgGet(param);
            case "airport_organization_bind" -> orgBind(param);
            default -> {}
        }
    }

    @SuppressWarnings("unchecked")
    private void onServicesReply(Map<String, Object> param) {
        String method = String.valueOf(param.get("method"));
        if ("flighttask_prepare".equals(method)) {
            Map<String, Object> data = map(param.get("data"));
            int result = toInt(data.get("result"));
            if (result == 0) {
                List<Map<String, Object>> tasks = jdbc.queryForList(
                        "SELECT * FROM `" + props.table("flighttask") + "` WHERE bid=? LIMIT 1", param.get("bid"));
                if (!tasks.isEmpty()) {
                    jdbc.update("UPDATE `" + props.table("flighttask") + "` SET status='sent', update_time=? WHERE bid=?",
                            now(), param.get("bid"));
                    if (toInt(tasks.get(0).get("task_type")) == 0) executeTask(tasks.get(0));
                }
            } else {
                jdbc.update("UPDATE `" + props.table("flighttask") + "` SET status='failed', error_code=?, update_time=? WHERE bid=?",
                        result, now(), param.get("bid"));
            }
        }
    }

    @SuppressWarnings("unchecked")
    private void onOsd(Map<String, Object> param) {
        Map<String, Object> data = map(param.get("data"));
        if (data.containsKey("best_link_gateway")) {
            jdbc.update("INSERT INTO `" + props.table("flightosd") + "` (sn, dock_sn, latitude, longitude, create_time) VALUES (?,?,?,?,?)",
                    param.get("sn"), param.get("gateway"), data.get("latitude"), data.get("longitude"), now());
        }
        String gateway = String.valueOf(param.getOrDefault("gateway", param.get("sn")));
        cacheNum(gateway, data, "total_flight_distance");
        cacheNum(gateway, data, "total_flight_time");
        cacheNum(gateway, data, "total_flight_sorties");
        cacheNum(gateway, data, "mode_code");
        cacheNum(gateway, data, "horizontal_speed");
        cacheNum(gateway, data, "vertical_speed");
        Map<String, Object> sub = map(data.get("sub_device"));
        if (sub.get("device_sn") != null) subscribeSn(String.valueOf(sub.get("device_sn")));
        recordTrackPoint(param, data);
    }

    private void onStatus(Map<String, Object> param) {
        if ("update_topo".equals(String.valueOf(param.get("method")))) {
            reply(param, "sys/product/" + param.get("sn") + "/status_reply", Map.of("result", 0));
        }
    }

    @SuppressWarnings("unchecked")
    private void onProgress(Map<String, Object> param) {
        Map<String, Object> data = map(param.get("data"));
        if (toInt(data.get("result")) >= 1) return;
        Map<String, Object> output = map(data.get("output"));
        Map<String, Object> ext = map(output.get("ext"));
        Map<String, Object> progress = map(output.get("progress"));
        String flightId = String.valueOf(ext.get("flight_id"));
        String status = String.valueOf(output.getOrDefault("status", ""));
        long millis = System.currentTimeMillis();
        jdbc.update("INSERT INTO `" + props.table("flightrecord") + "` (sn, flight_id, track_id, current_waypoint_index, media_count, wayline_mission_state, wayline_id, current_step, percent, timestamp, create_time, update_time) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
                param.get("sn"), flightId, ext.get("track_id"), ext.get("current_waypoint_index"),
                ext.getOrDefault("media_count", 0), ext.get("wayline_mission_state"), ext.get("wayline_id"),
                progress.get("current_step"), progress.get("percent"), millis, millis, millis);
        if (!status.isBlank() && !"null".equals(status)) {
            Long end = "ok".equals(status) ? now() : null;
            jdbc.update("UPDATE `" + props.table("flighttask") + "` SET status=?, now_point=?, end_time=IFNULL(?, end_time), update_time=? WHERE bid=?",
                    status, ext.get("current_waypoint_index"), end, now(), flightId);
        }
        int state = toInt(ext.get("wayline_mission_state"));
        String trackId = String.valueOf(ext.getOrDefault("track_id", ""));
        if (!trackId.isBlank() && !"null".equals(trackId)) {
            if (state == 5) startTrack(trackId, flightId, String.valueOf(param.getOrDefault("gateway", "")));
            if (state == 7 && ext.get("break_point") != null) recordBreakpoint(trackId, flightId, map(ext.get("break_point")));
            if (state == 9) jdbc.update("UPDATE `" + props.table("flight_track") + "` SET track_status='completed', update_time=? WHERE track_id=?",
                    System.currentTimeMillis(), trackId);
        }
    }

    private void startTrack(String trackId, String flightId, String dockSn) {
        if (!tableExists("flight_track")) return;
        Integer count = jdbc.queryForObject("SELECT COUNT(*) FROM `" + props.table("flight_track") + "` WHERE track_id=?", Integer.class, trackId);
        if (count != null && count > 0) {
            jdbc.update("UPDATE `" + props.table("flight_track") + "` SET track_status='recording', update_time=? WHERE track_id=?",
                    System.currentTimeMillis(), trackId);
            return;
        }
        List<Map<String, Object>> tasks = jdbc.queryForList("SELECT id, equipment_id, airline_id FROM `" + props.table("flighttask") + "` WHERE bid=? LIMIT 1", flightId);
        Object taskId = tasks.isEmpty() ? null : tasks.get(0).get("id");
        Object equipmentId = tasks.isEmpty() ? null : tasks.get(0).get("equipment_id");
        Object airlineId = tasks.isEmpty() ? null : tasks.get(0).get("airline_id");
        jdbc.update("INSERT INTO `" + props.table("flight_track") + "` (flight_id, track_id, task_id, equipment_id, dock_sn, track_type, track_status, wayline_id, start_time, create_time, update_time) VALUES (?,?,?,?,?,'wayline','recording',?,?,?,?)",
                flightId, trackId, taskId, equipmentId, dockSn, airlineId, System.currentTimeMillis(), System.currentTimeMillis(), System.currentTimeMillis());
    }

    private void recordBreakpoint(String trackId, String flightId, Map<String, Object> bp) {
        if (!tableExists("flight_breakpoint")) return;
        jdbc.update("INSERT INTO `" + props.table("flight_breakpoint") + "` (flight_id, track_id, break_index, break_state, break_progress, break_reason, latitude, longitude, height, attitude_head, wayline_id, break_time, create_time) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
                flightId, trackId, bp.get("index"), bp.get("state"), bp.get("progress"), bp.get("break_reason"),
                bp.get("latitude"), bp.get("longitude"), bp.get("height"), bp.get("attitude_head"), bp.get("wayline_id"),
                System.currentTimeMillis(), System.currentTimeMillis());
        jdbc.update("UPDATE `" + props.table("flight_track") + "` SET has_breakpoint=1, breakpoint_count=IFNULL(breakpoint_count,0)+1, update_time=? WHERE track_id=?",
                System.currentTimeMillis(), trackId);
    }

    private void recordTrackPoint(Map<String, Object> param, Map<String, Object> data) {
        if (!tableExists("flight_track_point") || data.get("latitude") == null) return;
        String gateway = String.valueOf(param.getOrDefault("gateway", ""));
        if (gateway.isBlank()) return;
        List<Map<String, Object>> active = jdbc.queryForList(
                "SELECT track_id, flight_id FROM `" + props.table("flightrecord") + "` WHERE sn=? AND wayline_mission_state IN (5,6,8) ORDER BY id DESC LIMIT 1",
                gateway);
        if (active.isEmpty() || active.get(0).get("track_id") == null) return;
        long millis = System.currentTimeMillis();
        jdbc.update("INSERT INTO `" + props.table("flight_track_point") + "` (track_id, flight_id, latitude, longitude, height, timestamp, create_time) VALUES (?,?,?,?,?,?,?)",
                active.get(0).get("track_id"), active.get(0).get("flight_id"), data.get("latitude"), data.get("longitude"),
                data.get("height"), millis, millis);
    }

    @SuppressWarnings("unchecked")
    private void saveMedia(Map<String, Object> param) {
        Map<String, Object> file = map(map(param.get("data")).get("file"));
        if (file.isEmpty()) return;
        Object flightId = map(param.get("data")).getOrDefault("flight_id", "");
        jdbc.update("INSERT INTO `" + props.table("media") + "` (name, object_key, sn, flight_id, path, create_time) VALUES (?,?,?,?,?,?)",
                file.getOrDefault("name", ""), file.getOrDefault("object_key", file.get("url") == null ? "" : file.get("url")),
                param.getOrDefault("sn", ""), flightId == null ? "" : flightId, "", now());
    }

    @SuppressWarnings("unchecked")
    private void saveHms(Map<String, Object> param) {
        Map<String, Object> data = map(param.get("data"));
        Object list = data.get("list");
        if (!(list instanceof List<?> alarms)) return;
        for (Object alarmObj : alarms) {
            Map<String, Object> alarm = map(alarmObj);
            jdbc.update("INSERT INTO `" + props.table("hmscenter") + "` (code, device_type, sn, create_time, update_time) VALUES (?,?,?,?,?)",
                    alarm.get("code") == null ? "" : alarm.get("code"),
                    alarm.get("device_type") == null ? "" : alarm.get("device_type"),
                    param.get("sn") == null ? "" : param.get("sn"), now(), now());
        }
    }

    private void saveOta(Map<String, Object> param) {
        if (!tableExists("firmware_upgrade_task")) return;
        jdbc.update("UPDATE `" + props.table("firmware_upgrade_task") + "` SET status='in_progress', update_time=NOW() WHERE task_id=?",
                param.get("bid"));
    }

    private void saveReturn(Map<String, Object> param) {
        if (!tableExists("flight_return_track")) return;
        jdbc.update("INSERT INTO `" + props.table("flight_return_track") + "` (flight_id, planned_points, trigger_time, create_time) VALUES (?,?,?,?)",
                param.get("bid") == null ? "" : param.get("bid"), safeJson(param), System.currentTimeMillis(), System.currentTimeMillis());
    }

    @SuppressWarnings("unchecked")
    private void resourceReply(Map<String, Object> param) {
        Map<String, Object> data = map(param.get("data"));
        Object flightId = data.get("flight_id");
        List<Map<String, Object>> tasks = jdbc.queryForList("SELECT file_url FROM `" + props.table("flighttask") + "` WHERE bid=? LIMIT 1", flightId);
        String url = tasks.isEmpty() ? "" : cdn() + String.valueOf(tasks.get(0).getOrDefault("file_url", ""));
        Map<String, Object> output = new LinkedHashMap<>();
        output.put("file", Map.of("fingerprint", "", "url", url));
        reply(param, "thing/product/" + param.get("sn") + "/requests_reply", Map.of("result", 0, "output", output));
    }

    private void storageReply(Map<String, Object> param) {
        Map<String, Object> data = map(param.get("data"));
        if (toInt(data.get("module")) >= 1) return;
        Map<String, Object> output = new LinkedHashMap<>();
        AppProperties.Storage storage = props.getStorage();
        output.put("bucket", storage.getBucket());
        output.put("credentials", sts());
        output.put("endpoint", storage.getEndpoint());
        output.put("object_key_prefix", param.get("sn"));
        output.put("provider", storage.minio() ? "minio" : "ali");
        output.put("region", storage.getRegion());
        reply(param, "thing/product/" + param.get("sn") + "/requests_reply", Map.of("result", 0, "output", output));
    }

    private void configReply(Map<String, Object> param) {
        Map<String, Object> data = new LinkedHashMap<>();
        data.put("ntp_server_host", props.getDji().getNtpHost());
        data.put("ntp_server_port", props.getDji().getNtpPort());
        data.put("app_id", props.getDji().getAppId());
        data.put("app_key", props.getDji().getAppKey());
        data.put("app_license", props.getDji().getAppLicense());
        Map<String, Object> msg = base(String.valueOf(param.get("bid")), "config", "thing/product/" + param.get("sn") + "/requests_reply");
        msg.put("tid", param.get("tid"));
        msg.put("gateway", param.get("sn"));
        msg.put("data", data);
        publish(msg);
    }

    @SuppressWarnings("unchecked")
    private void bindStatus(Map<String, Object> param) {
        List<Map<String, Object>> devices = (List<Map<String, Object>>) map(param.get("data")).getOrDefault("devices", List.of());
        List<Map<String, Object>> status = new ArrayList<>();
        if (devices != null) {
            for (Object deviceObj : devices) {
                Map<String, Object> device = map(deviceObj);
                String sn = String.valueOf(device.get("sn"));
                List<Map<String, Object>> rows = jdbc.queryForList(
                        "SELECT e.is_bind_organization, e.project_id, e.device_callsign, p.name project_name FROM `"
                                + props.table("equipment") + "` e LEFT JOIN `" + props.table("project") + "` p ON e.project_id=p.id WHERE e.sn=? LIMIT 1",
                        sn);
                Map<String, Object> item = new LinkedHashMap<>();
                item.put("sn", sn);
                if (rows.isEmpty()) {
                    item.put("is_device_bind_organization", false);
                    item.put("organization_id", "");
                    item.put("organization_name", "");
                    item.put("device_callsign", "");
                } else {
                    Map<String, Object> row = rows.get(0);
                    item.put("is_device_bind_organization", "1".equals(String.valueOf(row.get("is_bind_organization"))));
                    item.put("organization_id", row.get("project_id") == null ? "" : String.valueOf(row.get("project_id")));
                    item.put("organization_name", row.get("project_name") == null ? "" : row.get("project_name"));
                    item.put("device_callsign", row.get("device_callsign") == null ? "" : row.get("device_callsign"));
                }
                status.add(item);
            }
        }
        reply(param, "thing/product/" + param.get("sn") + "/requests_reply",
                Map.of("result", 0, "output", Map.of("bind_status", status)));
    }

    private void orgGet(Map<String, Object> param) {
        Object orgId = map(param.get("data")).get("organization_id");
        List<Map<String, Object>> rows = jdbc.queryForList("SELECT name FROM `" + props.table("project") + "` WHERE id=? LIMIT 1", orgId);
        String name = rows.isEmpty() ? "" : String.valueOf(rows.get(0).get("name"));
        reply(param, "thing/product/" + param.get("sn") + "/requests_reply",
                Map.of("result", name.isBlank() ? 1 : 0, "output", Map.of("organization_name", name)));
    }

    @SuppressWarnings("unchecked")
    private void orgBind(Map<String, Object> param) {
        List<?> devices = (List<?>) map(param.get("data")).getOrDefault("bind_devices", List.of());
        List<Map<String, Object>> errors = new ArrayList<>();
        boolean ok = true;
        String dockSn = null;
        List<String> drones = new ArrayList<>();
        for (Object deviceObj : devices) {
            Map<String, Object> device = map(deviceObj);
            String sn = String.valueOf(device.get("sn"));
            Object orgId = device.get("organization_id");
            Integer projects = jdbc.queryForObject("SELECT COUNT(*) FROM `" + props.table("project") + "` WHERE id=?", Integer.class, orgId);
            if (projects == null || projects == 0) {
                ok = false;
                errors.add(Map.of("sn", sn, "err_code", 210232));
                continue;
            }
            int[] model = parseModel(String.valueOf(device.get("device_model_key")));
            if (model[0] == 3) dockSn = sn;
            if (model[0] == 0) drones.add(sn);
            String callsign = String.valueOf(device.getOrDefault("device_callsign", sn));
            Integer exists = jdbc.queryForObject("SELECT COUNT(*) FROM `" + props.table("equipment") + "` WHERE sn=?", Integer.class, sn);
            if (exists != null && exists > 0) {
                jdbc.update("UPDATE `" + props.table("equipment") + "` SET device_binding_code=?, project_id=?, device_callsign=?, domain=?, type_code=?, sub_type=?, is_bind_organization='1', bind_time=?, update_time=? WHERE sn=?",
                        device.get("device_binding_code"), orgId, callsign, model[0], model[1], model[2], now(), now(), sn);
            } else {
                jdbc.update("INSERT INTO `" + props.table("equipment") + "` (sn, nickname, device_binding_code, project_id, device_callsign, domain, type_code, sub_type, device_category, manufacturer, is_bind_organization, bind_time, firmware_version, create_time, update_time) VALUES (?,?,?,?,?,?,?,?,?,'0','1',?,'',?,?)",
                        sn, callsign, device.get("device_binding_code"), orgId, callsign, model[0], model[1], model[2],
                        model[0] == 3 ? "3" : "0", now(), now(), now());
                subscribeSn(sn);
            }
        }
        if (dockSn != null) {
            List<Map<String, Object>> docks = jdbc.queryForList("SELECT id FROM `" + props.table("equipment") + "` WHERE sn=? LIMIT 1", dockSn);
            if (!docks.isEmpty()) {
                for (String drone : drones) {
                    jdbc.update("UPDATE `" + props.table("equipment") + "` SET parent_id=? WHERE sn=?", docks.get(0).get("id"), drone);
                }
            }
        }
        reply(param, "thing/product/" + param.get("sn") + "/requests_reply",
                Map.of("result", ok ? 0 : 1, "output", Map.of("err_infos", errors)));
    }

    private Map<String, Object> sts() {
        AppProperties.Storage storage = props.getStorage();
        if (storage.minio()) {
            return Map.of(
                    "access_key_id", storage.getMinioAccessKey(),
                    "access_key_secret", storage.getMinioSecretKey(),
                    "expire", 86400,
                    "security_token", "");
        }
        if (storage.getAccessKeyId().isBlank() || storage.getRoleArn().isBlank()) {
            return Map.of("access_key_id", "", "access_key_secret", "", "expire", 0, "security_token", "");
        }
        try {
            return requestSts(storage);
        } catch (Exception e) {
            log.warn("STS 获取失败: {}", e.getMessage());
            return Map.of("access_key_id", "", "access_key_secret", "", "expire", 0, "security_token", "");
        }
    }

    private Map<String, Object> requestSts(AppProperties.Storage storage) throws Exception {
        String nonce = UUID.randomUUID().toString().substring(0, 8);
        String timestamp = java.time.format.DateTimeFormatter.ofPattern("yyyy-MM-dd'T'HH:mm:ss'Z'")
                .withZone(java.time.ZoneOffset.UTC).format(Instant.now());
        Map<String, String> params = new java.util.TreeMap<>();
        params.put("Format", "JSON");
        params.put("Version", "2015-04-01");
        params.put("AccessKeyId", storage.getAccessKeyId());
        params.put("SignatureMethod", "HMAC-SHA1");
        params.put("SignatureVersion", "1.0");
        params.put("SignatureNonce", nonce);
        params.put("Action", "AssumeRole");
        params.put("RoleArn", storage.getRoleArn());
        params.put("RoleSessionName", storage.getRoleSessionName());
        params.put("DurationSeconds", String.valueOf(storage.getStsDuration()));
        params.put("Timestamp", timestamp);
        String canonical = params.entrySet().stream()
                .map(e -> url(e.getKey()) + "=" + url(e.getValue()))
                .reduce((a, b) -> a + "&" + b).orElse("");
        String stringToSign = "POST&" + url("/") + "&" + url(canonical);
        javax.crypto.Mac mac = javax.crypto.Mac.getInstance("HmacSHA1");
        mac.init(new javax.crypto.spec.SecretKeySpec((storage.getAccessKeySecret() + "&").getBytes(StandardCharsets.UTF_8), "HmacSHA1"));
        String signature = java.util.Base64.getEncoder().encodeToString(mac.doFinal(stringToSign.getBytes(StandardCharsets.UTF_8)));
        params.put("Signature", signature);
        String form = params.entrySet().stream().map(e -> url(e.getKey()) + "=" + url(e.getValue())).reduce((a, b) -> a + "&" + b).orElse("");
        java.net.http.HttpRequest request = java.net.http.HttpRequest.newBuilder(java.net.URI.create(storage.getStsUrl()))
                .header("Content-Type", "application/x-www-form-urlencoded")
                .POST(java.net.http.HttpRequest.BodyPublishers.ofString(form))
                .build();
        java.net.http.HttpResponse<String> response = java.net.http.HttpClient.newHttpClient()
                .send(request, java.net.http.HttpResponse.BodyHandlers.ofString());
        JsonNode node = json.readTree(response.body());
        JsonNode credentials = node.path("Credentials");
        Map<String, Object> out = new LinkedHashMap<>();
        out.put("access_key_id", credentials.path("AccessKeyId").asText(""));
        out.put("access_key_secret", credentials.path("AccessKeySecret").asText(""));
        out.put("security_token", credentials.path("SecurityToken").asText(""));
        out.put("expire", storage.getStsDuration());
        return out;
    }

    private void reply(Map<String, Object> param, String topic, Object data) {
        Map<String, Object> msg = base(String.valueOf(param.get("bid")), String.valueOf(param.get("method")), topic);
        msg.put("tid", param.get("tid"));
        msg.put("data", data);
        publish(msg);
    }

    private Map<String, Object> base(String bid, String method, String topic) {
        Map<String, Object> msg = new LinkedHashMap<>();
        msg.put("bid", bid);
        msg.put("tid", UUID.randomUUID().toString());
        msg.put("timestamp", System.currentTimeMillis());
        msg.put("method", method);
        msg.put("topic", topic);
        return msg;
    }

    private void cacheNum(String gateway, Map<String, Object> data, String field) {
        if (data.get(field) != null) osdCache.put(gateway + "_" + field, data.get(field));
    }

    private String cdn() {
        String cdn = props.getStorage().getCdnUrl();
        if (cdn == null || cdn.isBlank()) return "";
        return cdn.endsWith("/") ? cdn.substring(0, cdn.length() - 1) : cdn;
    }

    private boolean tableExists(String logical) {
        return schema.hasTable(logical);
    }

    @SuppressWarnings("unchecked")
    private Map<String, Object> map(Object value) {
        if (value instanceof Map<?, ?> m) return (Map<String, Object>) m;
        return new LinkedHashMap<>();
    }

    private int toInt(Object value) {
        if (value instanceof Number n) return n.intValue();
        try { return Integer.parseInt(String.valueOf(value)); } catch (Exception e) { return 0; }
    }

    private long now() { return Instant.now().getEpochSecond(); }

    private int[] parseModel(String key) {
        String[] p = key == null ? new String[0] : key.split("-");
        int domain = p.length > 0 ? toInt(p[0]) : 0;
        int type = p.length > 1 ? toInt(p[1]) : 0;
        int sub = p.length > 2 ? toInt(p[2]) : 0;
        return new int[]{domain, type, sub};
    }

    private String safeJson(Object value) {
        try { return json.writeValueAsString(value); } catch (Exception e) { return "{}"; }
    }

    private static String url(String raw) {
        return java.net.URLEncoder.encode(raw, StandardCharsets.UTF_8).replace("+", "%20").replace("*", "%2A").replace("%7E", "~");
    }

    private void note(String line) {
        synchronized (logs) {
            logs.add(Instant.now() + " " + line);
            if (logs.size() > 200) logs.remove(0);
        }
        log.info(line);
    }

    @PreDestroy
    public void shutdown() {
        stopping.set(true);
        connector.shutdownNow();
        try { if (client != null && client.isConnected()) client.disconnect(); } catch (Exception ignored) {}
    }
}
