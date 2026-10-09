package com.nexushive.platform;

import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;
import com.nexushive.common.ApiException;
import com.nexushive.config.AppProperties;
import com.nexushive.db.CrudEngine;
import com.nexushive.dji.DjiRuntime;
import com.nexushive.platform.entity.AdminAccount;
import com.nexushive.security.PasswordVerifier;
import jakarta.servlet.http.HttpServletRequest;
import org.springframework.jdbc.core.JdbcTemplate;
import org.springframework.stereotype.Service;
import org.springframework.web.multipart.MultipartFile;

import java.nio.file.Files;
import java.nio.file.Path;
import java.security.MessageDigest;
import java.time.Instant;
import java.time.LocalDate;
import java.util.ArrayList;
import java.util.HexFormat;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.UUID;

@Service
public class PlatformHttp {
    private final AuthService auth;
    private final CrudEngine crud;
    private final AppProperties props;
    private final DjiRuntime dji;
    private final ObjectMapper json;
    private final PasswordVerifier passwords = new PasswordVerifier();

    public PlatformHttp(AuthService auth, CrudEngine crud, AppProperties props, DjiRuntime dji, ObjectMapper json) {
        this.auth = auth;
        this.crud = crud;
        this.props = props;
        this.dji = dji;
        this.json = json;
    }

    public Map<String, Object> adminIndex(AdminAccount admin, HttpServletRequest request) {
        List<Map<String, Object>> menus = auth.menus(admin.getId());
        if (menus.isEmpty()) throw new ApiException("无后台菜单，请联系超级管理员！");
        Map<String, Object> info = auth.adminInfo(admin, true);
        String siteName = auth.configValue("site_name", "NEXUS HIVE");
        if (siteName.isBlank() || List.of("NEXUS HIVE", "Nexus Hive", "NexusHive", "Nexus Hive 开源版", "中建智科").contains(siteName)) {
            siteName = "NEXUS HIVE";
        }
        Map<String, Object> upload = new LinkedHashMap<>();
        upload.put("maxSize", auth.configValue("upload_max_size", "10mb"));
        upload.put("saveName", auth.configValue("upload_save_name", "/storage/{topic}/{year}{mon}{day}/{filename}{filesha1}{.suffix}"));
        upload.put("allowedSuffixes", auth.configValue("upload_allowed_suffixes", "jpg,png,bmp,jpeg,gif,webp,zip,rar,wav,mp4,mp3,kmz,kml,wpml"));
        upload.put("allowedMimeTypes", auth.configValue("upload_allowed_mime_types", ""));
        Map<String, Object> site = new LinkedHashMap<>();
        site.put("siteName", siteName);
        site.put("version", auth.configValue("version", "1.0.8"));
        site.put("apiUrl", "https://buildadmin.com");
        site.put("upload", upload);
        site.put("cdnUrl", cdn(request));
        site.put("cdnUrlParams", "");
        Map<String, Object> data = new LinkedHashMap<>();
        data.put("adminInfo", info);
        data.put("menus", menus);
        data.put("siteConfig", site);
        data.put("terminal", Map.of("phpDevelopmentServer", false, "npmPackageManager", "pnpm"));
        return data;
    }

    public Map<String, Object> apiIndex(HttpServletRequest request) {
        String siteName = auth.configValue("site_name", "NEXUS HIVE");
        if (siteName.isBlank() || List.of("NEXUS HIVE", "Nexus Hive", "NexusHive", "Nexus Hive 开源版", "中建智科").contains(siteName)) {
            siteName = "NEXUS HIVE";
        }
        Map<String, Object> site = new LinkedHashMap<>();
        site.put("siteName", siteName);
        site.put("version", auth.configValue("version", "1.0.8"));
        site.put("cdnUrl", cdn(request));
        site.put("upload", Map.of());
        site.put("recordNumber", auth.configValue("record_number", ""));
        site.put("cdnUrlParams", "");
        List<Map<String, Object>> rules = List.of();
        if (crud.meta().hasTable("user_rule")) {
            rules = crud.jdbc().queryForList(
                    "SELECT * FROM `" + props.table("user_rule") + "` WHERE status='1' AND no_login_valid=1 AND type IN ('route','nav','button') ORDER BY weigh DESC, id ASC");
            rules = tree(rules);
        }
        Map<String, Object> data = new LinkedHashMap<>();
        data.put("site", site);
        data.put("openMemberCenter", false);
        data.put("userInfo", Map.of());
        data.put("rules", rules);
        data.put("menus", List.of());
        return data;
    }

    public Map<String, Object> osd() {
        JdbcTemplate jdbc = crud.jdbc();
        List<Map<String, Object>> equipment = jdbc.queryForList("SELECT sn FROM `" + props.table("equipment") + "`");
        double distance = 0;
        double time = 0;
        double sorties = 0;
        for (Map<String, Object> row : equipment) {
            String sn = String.valueOf(row.get("sn"));
            distance += asDouble(dji.cacheGet(sn + "_total_flight_distance", 0));
            time += asDouble(dji.cacheGet(sn + "_total_flight_time", 0));
            sorties += asDouble(dji.cacheGet(sn + "_total_flight_sorties", 0));
        }
        Integer airlines = jdbc.queryForObject("SELECT COUNT(*) FROM `" + props.table("airline") + "`", Integer.class);
        Map<String, Object> data = new LinkedHashMap<>();
        data.put("total_flight_distance", String.format("%.2fkm", distance / 1000));
        data.put("total_flight_time", String.format("%.2fh", time / 3600));
        data.put("total_flight_sorties", (long) sorties);
        data.put("total_airline", airlines == null ? 0 : airlines);
        return data;
    }

    public Map<String, Object> configIndex() {
        String raw = auth.configValue("config_group", "[]");
        List<Map<String, Object>> groups = new ArrayList<>();
        try {
            JsonNode node = json.readTree(raw);
            if (node.isArray()) {
                for (JsonNode item : node) {
                    Map<String, Object> group = new LinkedHashMap<>();
                    group.put("key", item.path("key").asText());
                    group.put("value", item.path("value").asText());
                    groups.add(group);
                }
            }
        } catch (Exception ignored) {
            groups = List.of();
        }
        List<Map<String, Object>> rows = crud.jdbc().queryForList(
                "SELECT * FROM `" + props.table("config") + "` ORDER BY weigh DESC, id ASC");
        Map<String, Object> list = new LinkedHashMap<>();
        Map<String, String> titles = new LinkedHashMap<>();
        for (Map<String, Object> group : groups) {
            String key = String.valueOf(group.get("key"));
            titles.put(key, String.valueOf(group.get("value")));
            Map<String, Object> bucket = new LinkedHashMap<>();
            bucket.put("name", key);
            bucket.put("title", group.get("value"));
            bucket.put("list", new ArrayList<Map<String, Object>>());
            list.put(key, bucket);
        }
        for (Map<String, Object> row : rows) {
            String group = String.valueOf(row.get("group"));
            if (!list.containsKey(group)) continue;
            @SuppressWarnings("unchecked")
            Map<String, Object> bucket = (Map<String, Object>) list.get(group);
            @SuppressWarnings("unchecked")
            List<Map<String, Object>> items = (List<Map<String, Object>>) bucket.get("list");
            items.add(crud.present("config", row));
        }
        Map<String, Object> data = new LinkedHashMap<>();
        data.put("list", list);
        data.put("remark", crud.remark("routine/config"));
        data.put("configGroup", titles);
        data.put("quickEntrance", auth.configValue("config_quick_entrance", ""));
        return data;
    }

    public void configEdit(Map<String, Object> body) {
        if (body.isEmpty()) throw new ApiException("参数不能为空");
        for (Map.Entry<String, Object> entry : body.entrySet()) {
            if (!entry.getKey().matches("[A-Za-z0-9_]+")) continue;
            Object value = entry.getValue();
            if (value instanceof Map || value instanceof List) {
                try { value = json.writeValueAsString(value); } catch (Exception e) { value = String.valueOf(value); }
            }
            crud.jdbc().update("UPDATE `" + props.table("config") + "` SET value=? WHERE name=?", value, entry.getKey());
        }
    }

    public Map<String, Object> upload(MultipartFile file, String topic, long adminId) {
        if (file == null || file.isEmpty()) throw new ApiException("没有文件被上传");
        String safeTopic = topic == null || topic.isBlank() ? "default" : topic.replaceAll("[^A-Za-z0-9_-]", "");
        String original = file.getOriginalFilename() == null ? "file" : Path.of(file.getOriginalFilename()).getFileName().toString();
        String suffix = "";
        int dot = original.lastIndexOf('.');
        if (dot >= 0) suffix = original.substring(dot);
        LocalDate today = LocalDate.now();
        String day = String.format("%04d%02d%02d", today.getYear(), today.getMonthValue(), today.getDayOfMonth());
        String stored = UUID.randomUUID().toString().replace("-", "") + suffix;
        Path dir = Path.of(props.getPublicDir()).toAbsolutePath().normalize().resolve("storage").resolve(safeTopic).resolve(day);
        try {
            Files.createDirectories(dir);
            Path target = dir.resolve(stored);
            file.transferTo(target);
            String sha1 = sha1(Files.readAllBytes(target));
            String url = "/storage/" + safeTopic + "/" + day + "/" + stored;
            long now = Instant.now().getEpochSecond();
            crud.jdbc().update(
                    "INSERT INTO `" + props.table("attachment") + "` (topic, admin_id, user_id, url, name, size, mimetype, storage, sha1, create_time, last_upload_time) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
                    safeTopic, adminId, 0, url, original, file.getSize(), file.getContentType() == null ? "" : file.getContentType(),
                    "local", sha1, now, now);
            Map<String, Object> row = new LinkedHashMap<>();
            row.put("topic", safeTopic);
            row.put("url", url);
            row.put("name", original);
            row.put("size", file.getSize());
            row.put("mimetype", file.getContentType());
            row.put("storage", "local");
            row.put("sha1", sha1);
            return Map.of("file", row);
        } catch (ApiException e) {
            throw e;
        } catch (Exception e) {
            throw new ApiException(e.getMessage() == null ? "没有文件被上传" : e.getMessage());
        }
    }

    public List<Map<String, Object>> area(String province, String city) {
        if (!crud.meta().hasTable("area")) return List.of();
        String sql = "SELECT id AS value, name AS label FROM `" + props.table("area") + "` WHERE pid=? AND level=?";
        if (province == null || province.isBlank()) return crud.jdbc().queryForList(sql, 0, 1);
        if (city == null || city.isBlank()) return crud.jdbc().queryForList(sql, province, 2);
        return crud.jdbc().queryForList(sql, city, 3);
    }

    public String suffixSvg(String suffix) {
        String text = suffix == null || suffix.isBlank() ? "file" : suffix.replaceAll("[^A-Za-z0-9]", "");
        return "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"120\" height=\"140\"><rect width=\"120\" height=\"140\" rx=\"8\" fill=\"#6c757d\"/><text x=\"60\" y=\"80\" text-anchor=\"middle\" fill=\"white\" font-size=\"20\" font-family=\"sans-serif\">"
                + text + "</text></svg>";
    }

    public void hashAdmin(Map<String, Object> data, Object id, boolean insert) {
        Object raw = data.remove("password");
        Object groups = data.remove("group_arr");
        data.remove("salt");
        data.remove("login_failure");
        data.remove("last_login_time");
        data.remove("last_login_ip");
        if (insert) {
            long adminId = crud.insert("admin", data);
            if (raw != null && !String.valueOf(raw).isBlank()) {
                crud.jdbc().update("UPDATE `" + props.table("admin") + "` SET password=?, salt='' WHERE id=?", passwords.hash(String.valueOf(raw)), adminId);
            }
            saveGroups(adminId, groups);
            data.put("_saved", adminId);
            return;
        }
        if (id == null || String.valueOf(id).isBlank()) throw new ApiException("记录未找到");
        long adminId = id instanceof Number n ? n.longValue() : Long.parseLong(String.valueOf(id));
        if (raw != null && !String.valueOf(raw).isBlank()) {
            crud.jdbc().update("UPDATE `" + props.table("admin") + "` SET password=?, salt='' WHERE id=?", passwords.hash(String.valueOf(raw)), adminId);
        }
        crud.updateByPk("admin", adminId, data);
        if (groups != null) {
            crud.jdbc().update("DELETE FROM `" + props.table("admin_group_access") + "` WHERE uid=?", adminId);
            saveGroups(adminId, groups);
        }
    }

    public void decorateAdmins(List<Map<String, Object>> rows) {
        for (Map<String, Object> row : rows) decorateAdmin(row);
    }

    public void decorateAdmin(Map<String, Object> row) {
        row.remove("password");
        row.remove("salt");
        row.remove("login_failure");
        Object id = row.get("id");
        if (id == null) return;
        List<Map<String, Object>> groups = crud.jdbc().queryForList(
                "SELECT g.id, g.name FROM `" + props.table("admin_group_access") + "` a JOIN `" + props.table("admin_group")
                        + "` g ON a.group_id=g.id WHERE a.uid=?", id);
        row.put("group_arr", groups.stream().map(g -> g.get("id")).toList());
        row.put("group_name_arr", groups.stream().map(g -> g.get("name")).toList());
        row.put("password", "");
    }

    public Map<String, Object> ruleTree(String table, String quick) {
        String sql = "SELECT * FROM `" + props.table(table) + "`";
        List<Object> args = new ArrayList<>();
        if (quick != null && !quick.isBlank() && crud.meta().hasColumn(table, "title")) {
            sql += " WHERE title LIKE ?";
            args.add("%" + quick + "%");
        }
        sql += " ORDER BY weigh DESC, id ASC";
        List<Map<String, Object>> rows = crud.jdbc().queryForList(sql, args.toArray());
        rows.replaceAll(row -> crud.present(table, row));
        Map<String, Object> data = new LinkedHashMap<>();
        data.put("list", tree(rows));
        data.put("remark", crud.remark(table));
        if ("admin_group".equals(table)) data.put("group", List.of(1));
        return data;
    }

    public Map<String, Object> firmwareCreate(Map<String, Object> body) {
        String gateway = str(body.get("gateway_sn"));
        String deviceSn = str(body.get("device_sn"));
        int type = asInt(body.get("upgrade_type"));
        if (type == 0) type = 3;
        int dockId = asInt(body.get("dock_firmware_id"));
        int droneId = asInt(body.get("drone_firmware_id"));
        if (gateway.isBlank()) throw new ApiException("机场SN不能为空");
        if (type != 2 && type != 3) throw new ApiException("无效的升级类型");
        if (dockId == 0 && droneId == 0) throw new ApiException("请选择要升级的固件");
        List<Map<String, Object>> docks = crud.jdbc().queryForList(
                "SELECT * FROM `" + props.table("equipment") + "` WHERE sn=? LIMIT 1", gateway);
        if (docks.isEmpty()) throw new ApiException("机场设备不存在，请检查机场SN是否正确");
        Integer pending = crud.jdbc().queryForObject(
                "SELECT COUNT(*) FROM `" + props.table("firmware_upgrade_task") + "` WHERE gateway_sn=? AND status IN ('sent','in_progress')",
                Integer.class, gateway);
        if (pending != null && pending > 0) throw new ApiException("该机场已有正在进行的升级任务，请等待完成后再试");
        List<Map<String, Object>> devices = new ArrayList<>();
        String dockVersion = null;
        String droneVersion = null;
        if (dockId > 0) {
            Map<String, Object> firmware = firmware(dockId, "dock");
            dockVersion = str(firmware.get("version"));
            devices.add(devicePayload(gateway, firmware, type));
        }
        if (droneId > 0) {
            if (deviceSn.isBlank()) throw new ApiException("升级无人机固件时，无人机SN不能为空");
            Integer children = crud.jdbc().queryForObject(
                    "SELECT COUNT(*) FROM `" + props.table("equipment") + "` WHERE sn=? AND parent_id=?",
                    Integer.class, deviceSn, docks.get(0).get("id"));
            if (children == null || children == 0) throw new ApiException("无人机不存在或不属于该机场");
            Map<String, Object> firmware = firmware(droneId, "drone");
            droneVersion = str(firmware.get("version"));
            devices.add(devicePayload(deviceSn, firmware, type));
        }
        String taskId = UUID.randomUUID().toString();
        crud.jdbc().update(
                "INSERT INTO `" + props.table("firmware_upgrade_task") + "` (task_id, gateway_sn, device_sn, upgrade_type, dock_firmware_id, drone_firmware_id, dock_version, drone_version, status) VALUES (?,?,?,?,?,?,?,?, 'sent')",
                taskId, gateway, deviceSn.isBlank() ? null : deviceSn, type, dockId == 0 ? null : dockId, droneId == 0 ? null : droneId, dockVersion, droneVersion);
        Map<String, Object> message = new LinkedHashMap<>();
        message.put("bid", taskId);
        message.put("tid", UUID.randomUUID().toString());
        message.put("timestamp", System.currentTimeMillis());
        message.put("method", "ota_create");
        message.put("topic", "thing/product/" + gateway + "/services");
        message.put("data", Map.of("devices", devices));
        if (!dji.publish(message)) {
            crud.jdbc().update("DELETE FROM `" + props.table("firmware_upgrade_task") + "` WHERE task_id=?", taskId);
            throw new ApiException("创建升级任务失败: MQTT 消息发送失败");
        }
        return Map.of("task_id", taskId);
    }

    public Map<String, Object> mqttStatus() {
        Map<String, Object> live = dji.status();
        Map<String, Object> mqtt = new LinkedHashMap<>();
        mqtt.put("connected", live.get("connected"));
        mqtt.put("broker", live.get("uri"));
        mqtt.put("client_id", live.get("clientId"));
        mqtt.put("last_error", live.get("lastError"));
        mqtt.put("uptime", 0);
        mqtt.put("reconnect_count", 0);
        Map<String, Object> data = new LinkedHashMap<>();
        data.put("mqtt", mqtt);
        data.put("websocket", Map.of("port", 0, "connections", 0, "max_connections", 0));
        data.put("stats", Map.of("messages_in", 0, "messages_out", 0, "subscriptions", 0));
        data.put("logs", dji.recentLogs());
        return data;
    }

    public Map<String, Object> versionInfo() {
        return Map.of(
                "current_version", "1.0.8",
                "product_code", "nexus_hive",
                "updated_at", "2025-12-13 18:38:00",
                "runtime", "OpenJDK 17 / Spring Boot 3.5.7");
    }

    private Map<String, Object> firmware(int id, String type) {
        List<Map<String, Object>> rows = crud.jdbc().queryForList(
                "SELECT * FROM `" + props.table("firmware") + "` WHERE id=? AND status=1 AND device_type=? LIMIT 1", id, type);
        if (rows.isEmpty()) throw new ApiException("dock".equals(type) ? "机场固件不存在或已禁用" : "无人机固件不存在或已禁用");
        if (str(rows.get(0).get("file_url")).isBlank()) throw new ApiException("固件文件地址为空，无法升级");
        return rows.get(0);
    }

    private Map<String, Object> devicePayload(String sn, Map<String, Object> firmware, int type) {
        Map<String, Object> item = new LinkedHashMap<>();
        item.put("sn", sn);
        item.put("product_version", firmware.get("version"));
        item.put("file_url", firmware.get("file_url"));
        item.put("md5", firmware.get("md5"));
        item.put("file_size", firmware.get("file_size"));
        item.put("file_name", firmware.get("file_name"));
        item.put("firmware_upgrade_type", type);
        return item;
    }

    private void saveGroups(long adminId, Object groups) {
        List<?> list;
        if (groups instanceof List<?> values) list = values;
        else if (groups != null && !String.valueOf(groups).isBlank()) list = List.of(String.valueOf(groups).split(","));
        else return;
        for (Object group : list) {
            if (group == null || String.valueOf(group).isBlank()) continue;
            crud.jdbc().update("INSERT INTO `" + props.table("admin_group_access") + "` (uid, group_id) VALUES (?,?)", adminId, String.valueOf(group).trim());
        }
    }

    private List<Map<String, Object>> tree(List<Map<String, Object>> rows) {
        Map<Long, List<Map<String, Object>>> byPid = new LinkedHashMap<>();
        for (Map<String, Object> row : rows) {
            long pid = row.get("pid") instanceof Number n ? n.longValue() : 0;
            byPid.computeIfAbsent(pid, k -> new ArrayList<>()).add(row);
        }
        return children(byPid, 0);
    }

    private List<Map<String, Object>> children(Map<Long, List<Map<String, Object>>> byPid, long pid) {
        List<Map<String, Object>> list = byPid.getOrDefault(pid, List.of());
        for (Map<String, Object> item : list) {
            long id = item.get("id") instanceof Number n ? n.longValue() : 0;
            if (byPid.containsKey(id)) item.put("children", children(byPid, id));
        }
        return list;
    }

    private String cdn(HttpServletRequest request) {
        String cdn = props.getStorage().getCdnUrl();
        if (cdn != null && !cdn.isBlank()) return cdn;
        int port = request.getServerPort();
        String extra = (port == 80 || port == 443) ? "" : ":" + port;
        return request.getScheme() + "://" + request.getServerName() + extra;
    }

    private static String sha1(byte[] bytes) throws Exception {
        return HexFormat.of().formatHex(MessageDigest.getInstance("SHA-1").digest(bytes));
    }

    private static String str(Object value) { return value == null ? "" : String.valueOf(value); }

    private static int asInt(Object value) {
        if (value instanceof Number n) return n.intValue();
        try { return Integer.parseInt(String.valueOf(value)); } catch (Exception e) { return 0; }
    }

    private static double asDouble(Object value) {
        if (value instanceof Number n) return n.doubleValue();
        try { return Double.parseDouble(String.valueOf(value)); } catch (Exception e) { return 0; }
    }
}
