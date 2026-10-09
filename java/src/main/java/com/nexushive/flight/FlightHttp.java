package com.nexushive.flight;

import com.alibaba.excel.EasyExcel;
import com.nexushive.common.ApiException;
import com.nexushive.config.AppProperties;
import com.nexushive.db.CrudEngine;
import com.nexushive.dji.DjiRuntime;
import com.nexushive.platform.entity.AdminAccount;
import jakarta.servlet.http.HttpServletRequest;
import org.springframework.jdbc.core.JdbcTemplate;
import org.springframework.stereotype.Service;

import java.io.InputStream;
import java.nio.file.Files;
import java.nio.file.Path;
import java.security.MessageDigest;
import java.time.Instant;
import java.time.LocalDateTime;
import java.time.ZoneId;
import java.time.format.DateTimeFormatter;
import java.util.ArrayList;
import java.util.HexFormat;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.UUID;
import java.util.zip.ZipEntry;
import java.util.zip.ZipOutputStream;

@Service
public class FlightHttp {
    private static final DateTimeFormatter TS = DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss");
    private final CrudEngine crud;
    private final AppProperties props;
    private final DjiRuntime dji;
    private final ProductCatalog catalog;

    public FlightHttp(CrudEngine crud, AppProperties props, DjiRuntime dji, ProductCatalog catalog) {
        this.crud = crud;
        this.props = props;
        this.dji = dji;
        this.catalog = catalog;
    }

    public Map<String, Object> equipmentIndex(CrudEngine.Query query) {
        query.extraSql.add("m.`domain`=?");
        query.extraArgs.add(3);
        Map<String, Object> page = crud.page("equipment", query);
        @SuppressWarnings("unchecked")
        List<Map<String, Object>> list = (List<Map<String, Object>>) page.get("list");
        JdbcTemplate jdbc = crud.jdbc();
        for (Map<String, Object> row : list) {
            row.put("project", projectOf(row.get("project_id")));
            row.put("children", jdbc.queryForList(
                    "SELECT * FROM `" + props.table("equipment") + "` WHERE parent_id=? AND domain=0", row.get("id")));
        }
        return page;
    }

    public void equipmentAdd(Map<String, Object> data, AdminAccount admin) {
        data.remove("id");
        data.remove("create_time");
        data.remove("update_time");
        data.put("domain", 3);
        if (crud.meta().hasColumn("equipment", "admin_id") && admin != null && !data.containsKey("admin_id")) {
            data.put("admin_id", admin.getId());
        }
        long id = crud.insert("equipment", data);
        if (id <= 0) throw new ApiException("未添加任何行");
        Object sn = data.get("sn");
        if (sn != null) {
            dji.publish(Map.of("topic", "system/equipment/set/equipment_add", "new_sn", sn));
            dji.subscribeSn(String.valueOf(sn));
        }
    }

    public Map<String, Object> aircraftList() {
        List<Map<String, Object>> series = catalog.aircraftSeries();
        if (series.isEmpty() && catalog.aircraftCount() == 0) {
            throw new ApiException("产品配置文件异常");
        }
        return Map.of("series", series, "total", catalog.aircraftCount());
    }

    public Map<String, Object> rtmpConfig(String sn) {
        if (sn == null || sn.isBlank()) throw new ApiException("设备SN不能为空");
        List<Map<String, Object>> rows = crud.jdbc().queryForList(
                "SELECT * FROM `" + props.table("equipment") + "` WHERE sn=? LIMIT 1", sn);
        if (rows.isEmpty()) throw new ApiException("设备不存在");
        Map<String, Object> device = rows.get(0);
        if (num(device.get("domain")) == 0 && device.get("parent_id") != null) {
            List<Map<String, Object>> parents = crud.jdbc().queryForList(
                    "SELECT * FROM `" + props.table("equipment") + "` WHERE id=? LIMIT 1", device.get("parent_id"));
            if (parents.isEmpty()) throw new ApiException("未找到关联的机场设备");
            device = parents.get(0);
        }
        if (num(device.get("domain")) != 3) throw new ApiException("该设备类型不支持RTMP配置");
        String rtmp = blank(props.getZlm().getRtmpUrl());
        String http = blank(props.getZlm().getFlvUrl());
        List<Map<String, Object>> drones = crud.jdbc().queryForList(
                "SELECT sn FROM `" + props.table("equipment") + "` WHERE parent_id=? AND domain=0 LIMIT 1", device.get("id"));
        String droneSn = drones.isEmpty() ? "" : String.valueOf(drones.get(0).get("sn"));
        Map<String, Object> data = new LinkedHashMap<>();
        data.put("gateway_sn", device.get("sn"));
        data.put("drone_sn", droneSn);
        data.put("cabin", stream(rtmp, http, device.get("rtmp_cabin_stream_key"), device.get("rtmp_cabin_secret")));
        data.put("drone", stream(rtmp, http, device.get("rtmp_drone_stream_key"), device.get("rtmp_drone_secret")));
        data.put("srs_config", Map.of("rtmp_server", rtmp, "http_server", http));
        return data;
    }

    public void updateRtmp(Map<String, Object> body) {
        String sn = str(body.get("sn"));
        if (sn.isBlank()) throw new ApiException("设备SN不能为空");
        int n = crud.jdbc().update(
                "UPDATE `" + props.table("equipment") + "` SET rtmp_cabin_stream_key=?, rtmp_cabin_secret=?, rtmp_drone_stream_key=?, rtmp_drone_secret=?, update_time=? WHERE sn=? AND domain=3",
                str(body.get("cabin_stream_key")), str(body.get("cabin_secret")),
                str(body.get("drone_stream_key")), str(body.get("drone_secret")),
                Instant.now().getEpochSecond(), sn);
        if (n == 0) throw new ApiException("机场设备不存在");
    }

    public void airlineSave(Map<String, Object> data, boolean insert) {
        Object key = data.get("drone_model_key");
        if (key != null && !str(key).isBlank()) {
            Map<String, Object> info = catalog.find(str(key));
            if (info == null || info.get("name") == null) throw new ApiException("无效的飞行器型号");
            data.put("drone_model_name", info.get("name"));
        } else if (insert) {
            data.put("drone_model_key", null);
            data.put("drone_model_name", null);
        }
        if (data.get("template") != null && data.get("wayline") != null) {
            packKmz(data);
        }
        data.remove("id");
        data.remove("create_time");
        data.remove("update_time");
    }

    public Map<String, Object> addTask(Map<String, Object> data, AdminAccount admin) {
        boolean manual = "manual".equals(str(data.get("flight_mode")));
        boolean repeat = "2".equals(str(data.get("task_type"))) || num(data.get("task_type")) == 2;
        if (!manual) {
            if (data.get("airline_id") == null || str(data.get("airline_id")).isBlank()) {
                throw new ApiException(repeat ? "循环航线任务需要有效的航线！" : "下发失败,未查询到对应航线！");
            }
            Map<String, Object> wayline = crud.find("airline", data.get("airline_id"));
            if (wayline == null) throw new ApiException(repeat ? "循环航线任务需要有效的航线！" : "下发失败,未查询到对应航线！");
            data.put("file_url", wayline.get("kmz"));
            data.put("total_point", wayline.get("point_num"));
            if (wayline.get("kmz_md5") != null) data.put("file_fingerprint", wayline.get("kmz_md5"));
        }
        if ("".equals(data.get("execute_time"))) data.remove("execute_time");
        else if (data.get("execute_time") != null) data.put("execute_time", epoch(data.get("execute_time")));
        data.put("bid", UUID.randomUUID().toString());
        data.put("tid", UUID.randomUUID().toString());
        data.put("admin_id", admin.getId());
        data.put("task_type", str(data.getOrDefault("task_type", "0")));
        if (repeat) {
            data.put("status", "paused");
            long id = crud.insert("flighttask", data);
            return Map.of("id", id, "bid", data.get("bid"), "tid", data.get("tid"));
        }
        if (manual) {
            if (data.get("equipment_id") == null || str(data.get("equipment_id")).isBlank()) {
                throw new ApiException("手动飞行任务必须指定设备ID（机场ID）");
            }
            data.put("status", "sent");
            if ("0".equals(str(data.get("task_type")))) data.put("execute_time", Instant.now().getEpochSecond());
            if ("1".equals(str(data.get("task_type"))) && data.get("execute_time") == null) {
                throw new ApiException("定时任务必须指定执行时间");
            }
            long id = crud.insert("flighttask", data);
            Map<String, Object> out = new LinkedHashMap<>();
            out.put("id", id);
            out.put("bid", data.get("bid"));
            out.put("tid", data.get("tid"));
            out.put("task_type", data.get("task_type"));
            out.put("execute_time", data.get("execute_time"));
            return out;
        }
        data.put("status", "sent");
        if ("0".equals(str(data.get("task_type")))) data.put("execute_time", Instant.now().getEpochSecond());
        if ("1".equals(str(data.get("task_type"))) && data.get("execute_time") == null) {
            throw new ApiException("定时任务必须指定执行时间");
        }
        List<Map<String, Object>> eqs = crud.jdbc().queryForList(
                "SELECT sn FROM `" + props.table("equipment") + "` WHERE id=? LIMIT 1", data.get("equipment_id"));
        if (eqs.isEmpty()) throw new ApiException("下发失败,未查询到对应设备！");
        long id = crud.insert("flighttask", data);
        Map<String, Object> saved = new LinkedHashMap<>(data);
        saved.put("id", id);
        Map<String, Object> message = dji.buildPrepare(saved, String.valueOf(eqs.get(0).get("sn")));
        if (!dji.publish(message)) {
            crud.deleteByIds("flighttask", List.of(id));
            throw new ApiException("任务下发失败,MQTT发布失败");
        }
        return Map.of("id", id);
    }

    public String cancel(List<String> ids) {
        if (ids.isEmpty()) throw new ApiException("请选择要取消的任务");
        String marks = String.join(",", ids.stream().map(id -> "?").toList());
        List<Map<String, Object>> tasks = crud.jdbc().queryForList(
                "SELECT * FROM `" + props.table("flighttask") + "` WHERE id IN (" + marks + ")", ids.toArray());
        if (tasks.isEmpty()) throw new ApiException("未找到要取消的任务");
        int success = 0;
        int children = 0;
        List<String> failed = new ArrayList<>();
        List<String> terminal = List.of("ok", "failed", "canceled", "rejected", "timeout");
        for (Map<String, Object> task : tasks) {
            String status = str(task.get("status"));
            if (terminal.contains(status)) {
                failed.add(task.get("name") + ": 任务状态为 " + status + "，无法取消");
                continue;
            }
            if ("2".equals(str(task.get("task_type")))) {
                crud.jdbc().update("UPDATE `" + props.table("flighttask") + "` SET status='canceled', is_repeat_enabled='0', error_msg='用户手动取消', update_time=? WHERE id=?",
                        Instant.now().getEpochSecond(), task.get("id"));
                success++;
                List<Map<String, Object>> kids = crud.jdbc().queryForList(
                        "SELECT * FROM `" + props.table("flighttask") + "` WHERE parent_task_id=? AND status NOT IN ('ok','failed','canceled','rejected','timeout')",
                        task.get("id"));
                for (Map<String, Object> kid : kids) {
                    if (cancelOne(kid)) children++;
                }
                continue;
            }
            if (cancelOne(task)) success++;
            else failed.add(task.get("name") + ": MQTT消息发送失败");
        }
        if (success == 0 && !failed.isEmpty()) throw new ApiException("取消任务失败：" + String.join("；", failed));
        String msg = "成功取消 " + success + " 个任务";
        if (children > 0) msg += "（包含 " + children + " 个子任务）";
        if (!failed.isEmpty()) msg += "，失败 " + failed.size() + " 个：" + String.join("；", failed);
        return msg;
    }

    public Map<String, Object> reportMedia(Map<String, Object> body) {
        Map<String, Object> task = taskOf(body);
        if (!"manual".equals(str(task.get("flight_mode")))) throw new ApiException("该接口仅支持手动飞行任务");
        int count = num(body.get("media_count"));
        if (body.get("media_count") == null || count < 0) throw new ApiException("媒体数量参数无效");
        long now = Instant.now().getEpochSecond();
        Map<String, Object> patch = new LinkedHashMap<>();
        patch.put("media_total", count);
        patch.put("update_time", now);
        boolean synced = count > num(task.get("media_now"));
        if (synced) patch.put("media_now", count);
        if (body.get("photo_count") != null && crud.meta().hasColumn("flighttask", "photo_count")) {
            patch.put("photo_count", body.get("photo_count"));
        }
        if (body.get("video_count") != null && crud.meta().hasColumn("flighttask", "video_count")) {
            patch.put("video_count", body.get("video_count"));
        }
        StringBuilder set = new StringBuilder();
        List<Object> args = new ArrayList<>();
        for (Map.Entry<String, Object> entry : patch.entrySet()) {
            if (!set.isEmpty()) set.append(',');
            set.append('`').append(entry.getKey()).append("`=?");
            args.add(entry.getValue());
        }
        args.add(task.get("id"));
        crud.jdbc().update("UPDATE `" + props.table("flighttask") + "` SET " + set + " WHERE id=?", args.toArray());
        Map<String, Object> out = new LinkedHashMap<>();
        out.put("id", task.get("id"));
        out.put("media_total", count);
        out.put("update_time", now);
        if (synced) {
            out.put("media_now", count);
            out.put("synced", true);
        }
        return out;
    }

    public Map<String, Object> completeManual(Map<String, Object> body) {
        Map<String, Object> task = taskOf(body);
        if (!"manual".equals(str(task.get("flight_mode")))) throw new ApiException("该接口仅支持手动飞行任务");
        String status = str(task.get("status"));
        if (!"sent".equals(status) && !"in_progress".equals(status)) {
            throw new ApiException("任务状态不允许完成操作，当前状态: " + status);
        }
        long end = body.get("end_time") == null ? Instant.now().getEpochSecond() : epoch(body.get("end_time"));
        crud.jdbc().update("UPDATE `" + props.table("flighttask") + "` SET status='ok', end_time=?, update_time=? WHERE id=?",
                end, Instant.now().getEpochSecond(), task.get("id"));
        long start = epoch(task.get("execute_time"));
        Map<String, Object> out = new LinkedHashMap<>();
        out.put("id", task.get("id"));
        out.put("bid", task.get("bid"));
        out.put("status", "ok");
        out.put("end_time", end);
        out.put("duration", start > 0 ? end - start : null);
        out.put("media_total", task.get("media_total"));
        return out;
    }

    public Map<String, Object> export(HttpServletRequest request, Map<String, Object> params) {
        StringBuilder where = new StringBuilder(" WHERE 1=1");
        List<Object> args = new ArrayList<>();
        if (!str(params.get("quick_search")).isBlank()) {
            where.append(" AND t.name LIKE ?");
            args.add("%" + str(params.get("quick_search")) + "%");
        }
        for (String field : List.of("status", "task_type", "equipment_id", "airline_id")) {
            if (!str(params.get(field)).isBlank()) {
                where.append(" AND t.`").append(field).append("`=?");
                args.add(params.get(field));
            }
        }
        List<Map<String, Object>> rows = crud.jdbc().queryForList(
                "SELECT t.*, a.name airline_name, e.nickname equipment_name, ad.username admin_name FROM `"
                        + props.table("flighttask") + "` t LEFT JOIN `" + props.table("airline") + "` a ON t.airline_id=a.id LEFT JOIN `"
                        + props.table("equipment") + "` e ON t.equipment_id=e.id LEFT JOIN `" + props.table("admin")
                        + "` ad ON t.admin_id=ad.id" + where + " ORDER BY t.id DESC",
                args.toArray());
        List<List<String>> head = List.of(List.of("ID"), List.of("任务名称"), List.of("业务ID"), List.of("执行航线"),
                List.of("设备"), List.of("状态"), List.of("创建人"));
        List<List<Object>> data = new ArrayList<>();
        for (Map<String, Object> row : rows) {
            data.add(List.of(row.get("id"), str(row.get("name")), str(row.get("bid")), str(row.get("airline_name")),
                    str(row.get("equipment_name")), str(row.get("status")), str(row.get("admin_name"))));
        }
        String fileName = "flighttask-" + System.currentTimeMillis() + ".xlsx";
        Path dir = publicDir().resolve("exports");
        try {
            Files.createDirectories(dir);
            EasyExcel.write(dir.resolve(fileName).toFile()).head(head).sheet("任务").doWrite(data);
        } catch (Exception e) {
            throw new ApiException("导出失败：" + e.getMessage());
        }
        String base = request.getScheme() + "://" + request.getServerName()
                + (request.getServerPort() == 80 || request.getServerPort() == 443 ? "" : ":" + request.getServerPort());
        Map<String, Object> payload = new LinkedHashMap<>();
        payload.put("download_url", base + "/exports/" + fileName);
        payload.put("file_name", fileName);
        try {
            payload.put("file_size", Files.size(dir.resolve(fileName)));
        } catch (Exception e) {
            payload.put("file_size", 0);
        }
        return payload;
    }

    public Map<String, Object> errorDetail(Object id) {
        if (id == null || str(id).isBlank()) throw new ApiException("缺少任务ID参数");
        Map<String, Object> task = crud.find("flighttask", id);
        if (task == null) throw new ApiException("任务不存在");
        Map<String, Object> basic = new LinkedHashMap<>();
        basic.put("task_id", task.get("id"));
        basic.put("flight_id", task.get("bid"));
        basic.put("task_name", task.get("name"));
        basic.put("status", task.get("status"));
        basic.put("status_text", task.get("status"));
        basic.put("failed_time", task.get("failed_time"));
        Map<String, Object> detail = new LinkedHashMap<>();
        detail.put("basic", basic);
        detail.put("error", null);
        detail.put("location", null);
        detail.put("state", null);
        detail.put("suggestion", null);
        if (!str(task.get("error_code")).isBlank() || num(task.get("break_reason")) > 0) {
            Map<String, Object> error = new LinkedHashMap<>();
            if (!str(task.get("error_code")).isBlank()) {
                error.put("type", "prepare");
                error.put("code", task.get("error_code"));
                error.put("message", str(task.get("error_msg")).isBlank() ? task.get("error_code") : task.get("error_msg"));
            }
            if (num(task.get("break_reason")) > 0) {
                error.put("type", "execution");
                error.put("code", task.get("break_reason"));
                error.put("message", task.get("error_msg"));
            }
            detail.put("error", error);
            if (task.get("break_latitude") != null || task.get("break_longitude") != null) {
                detail.put("location", Map.of(
                        "latitude", task.get("break_latitude") == null ? "" : task.get("break_latitude"),
                        "longitude", task.get("break_longitude") == null ? "" : task.get("break_longitude"),
                        "waypoint_index", task.get("now_point") == null ? "" : task.get("now_point")));
            }
            detail.put("suggestion", "请检查航线文件、设备和断点记录后决定是否重新下发");
        }
        if (task.get("wayline_mission_state") != null) {
            detail.put("state", Map.of(
                    "wayline_mission_state", task.get("wayline_mission_state"),
                    "failed_step", task.get("failed_step") == null ? "" : task.get("failed_step"),
                    "current_waypoint", task.get("now_point") == null ? "" : task.get("now_point"),
                    "total_waypoint", task.get("total_point") == null ? "" : task.get("total_point")));
        }
        return detail;
    }

    public Map<String, Object> errorStats() {
        JdbcTemplate jdbc = crud.jdbc();
        String table = props.table("flighttask");
        Map<String, Object> stats = new LinkedHashMap<>();
        stats.put("total_failed", count("SELECT COUNT(*) FROM `" + table + "` WHERE status IN ('failed','rejected','paused')"));
        stats.put("prepare_error", count("SELECT COUNT(*) FROM `" + table + "` WHERE status='rejected'"));
        stats.put("execution_error", count("SELECT COUNT(*) FROM `" + table + "` WHERE break_reason>0"));
        stats.put("critical_error", 0);
        stats.put("retryable_error", 0);
        stats.put("user_action", 0);
        stats.put("device_issue", 0);
        return stats;
    }

    public void enrichFlighttask(Map<String, Object> page) {
        @SuppressWarnings("unchecked")
        List<Map<String, Object>> list = (List<Map<String, Object>>) page.get("list");
        if (list == null) return;
        for (Map<String, Object> row : list) {
            row.put("airline", nameOnly("airline", row.get("airline_id"), "name"));
            row.put("equipment", nameOnly("equipment", row.get("equipment_id"), "nickname"));
            row.put("admin", adminBrief(row.get("admin_id")));
        }
    }

    public void enrichAirline(Map<String, Object> page) {
        @SuppressWarnings("unchecked")
        List<Map<String, Object>> list = (List<Map<String, Object>>) page.get("list");
        if (list == null) return;
        for (Map<String, Object> row : list) row.put("project", projectOf(row.get("project_id")));
    }

    private boolean cancelOne(Map<String, Object> task) {
        List<Map<String, Object>> eqs = crud.jdbc().queryForList(
                "SELECT sn FROM `" + props.table("equipment") + "` WHERE id=? LIMIT 1", task.get("equipment_id"));
        if (eqs.isEmpty()) return false;
        String method = "in_progress".equals(str(task.get("status"))) ? "in_flight_wayline_cancel" : "flighttask_undo";
        Map<String, Object> msg = new LinkedHashMap<>();
        msg.put("bid", UUID.randomUUID().toString());
        msg.put("tid", UUID.randomUUID().toString());
        msg.put("timestamp", System.currentTimeMillis());
        msg.put("method", method);
        msg.put("topic", "thing/product/" + eqs.get(0).get("sn") + "/services");
        msg.put("data", "flighttask_undo".equals(method) ? Map.of("flight_ids", List.of(task.get("bid"))) : Map.of());
        if (!dji.publish(msg)) return false;
        crud.jdbc().update("UPDATE `" + props.table("flighttask") + "` SET status='canceled', error_msg='用户手动取消', update_time=? WHERE id=?",
                Instant.now().getEpochSecond(), task.get("id"));
        return true;
    }

    private Map<String, Object> taskOf(Map<String, Object> body) {
        if ((body.get("id") == null || str(body.get("id")).isBlank()) && str(body.get("bid")).isBlank()) {
            throw new ApiException("任务ID或业务ID不能为空");
        }
        if (body.get("id") != null && !str(body.get("id")).isBlank()) {
            Map<String, Object> row = crud.find("flighttask", body.get("id"));
            if (row == null) throw new ApiException("任务不存在");
            return row;
        }
        List<Map<String, Object>> rows = crud.jdbc().queryForList(
                "SELECT * FROM `" + props.table("flighttask") + "` WHERE bid=? LIMIT 1", body.get("bid"));
        if (rows.isEmpty()) throw new ApiException("任务不存在");
        return crud.present("flighttask", rows.get(0));
    }

    private void packKmz(Map<String, Object> data) {
        String name = str(data.get("name")).replaceAll("[\\\\/]+", "_");
        if (name.isBlank()) throw new ApiException("参数错误!");
        Path pub = publicDir();
        Path template = safePublic(pub, str(data.get("template")));
        Path wayline = safePublic(pub, str(data.get("wayline")));
        if (!Files.isRegularFile(template) || !Files.isRegularFile(wayline)) {
            throw new ApiException("航线文件不存在");
        }
        Path kmzDir = pub.resolve("uploads/kmz");
        Path urlDir = pub.resolve("kmz");
        Path kmz = kmzDir.resolve(name + ".kmz");
        try {
            Files.createDirectories(kmzDir);
            Files.createDirectories(urlDir);
            Path temp = Files.createTempFile("kmz-", ".zip");
            try (ZipOutputStream zip = new ZipOutputStream(Files.newOutputStream(temp))) {
                put(zip, template, "wpmz/template.kml");
                put(zip, wayline, "wpmz/waylines.wpml");
            }
            Files.move(temp, kmz, java.nio.file.StandardCopyOption.REPLACE_EXISTING);
            Files.copy(kmz, urlDir.resolve(name + ".kmz"), java.nio.file.StandardCopyOption.REPLACE_EXISTING);
            data.put("kmz", "/kmz/" + name + ".kmz");
            data.put("kmz_md5", md5(kmz));
            uploadOss(kmz, "kmz/" + name + ".kmz");
        } catch (ApiException e) {
            throw e;
        } catch (Exception e) {
            throw new ApiException(e.getMessage() == null ? "未添加任何行" : e.getMessage());
        }
    }

    private void uploadOss(Path file, String key) {
        var storage = props.getStorage();
        if (storage.getAccessKeyId().isBlank() || storage.getBucket().isBlank() || storage.getEndpoint().isBlank()) return;
        String endpoint = storage.getEndpoint();
        if (!endpoint.startsWith("http")) endpoint = "https://" + endpoint;
        try {
            var client = new com.aliyun.oss.OSSClientBuilder().build(endpoint, storage.getAccessKeyId(), storage.getAccessKeySecret());
            try {
                client.putObject(storage.getBucket(), key, file.toFile());
            } finally {
                client.shutdown();
            }
        } catch (Exception ignored) {
            // OSS 失败不阻断航线保存，与 PHP 一致。
        }
    }

    private static void put(ZipOutputStream zip, Path file, String name) throws Exception {
        zip.putNextEntry(new ZipEntry(name));
        try (InputStream in = Files.newInputStream(file)) {
            in.transferTo(zip);
        }
        zip.closeEntry();
    }

    private static String md5(Path file) throws Exception {
        MessageDigest digest = MessageDigest.getInstance("MD5");
        digest.update(Files.readAllBytes(file));
        return HexFormat.of().formatHex(digest.digest());
    }

    private Path safePublic(Path pub, String relative) {
        String cleaned = relative.startsWith("/") ? relative.substring(1) : relative;
        Path resolved = pub.resolve(cleaned).normalize();
        if (!resolved.startsWith(pub)) throw new ApiException("参数错误!");
        return resolved;
    }

    private Path publicDir() {
        Path dir = Path.of(props.getPublicDir()).toAbsolutePath().normalize();
        if (!Files.isDirectory(dir)) {
            throw new ApiException("public 目录不存在: " + dir);
        }
        return dir;
    }

    private Map<String, Object> projectOf(Object id) {
        if (id == null) return Map.of("name", "");
        List<Map<String, Object>> rows = crud.jdbc().queryForList(
                "SELECT name FROM `" + props.table("project") + "` WHERE id=? LIMIT 1", id);
        return Map.of("name", rows.isEmpty() || rows.get(0).get("name") == null ? "" : rows.get(0).get("name"));
    }

    private Map<String, Object> nameOnly(String table, Object id, String field) {
        if (id == null) return Map.of(field, "");
        List<Map<String, Object>> rows = crud.jdbc().queryForList(
                "SELECT `" + field + "` FROM `" + props.table(table) + "` WHERE id=? LIMIT 1", id);
        return Map.of(field, rows.isEmpty() || rows.get(0).get(field) == null ? "" : rows.get(0).get(field));
    }

    private Map<String, Object> adminBrief(Object id) {
        if (id == null) return Map.of("username", "", "nickname", "");
        List<Map<String, Object>> rows = crud.jdbc().queryForList(
                "SELECT username, nickname FROM `" + props.table("admin") + "` WHERE id=? LIMIT 1", id);
        if (rows.isEmpty()) return Map.of("username", "", "nickname", "");
        return Map.of("username", str(rows.get(0).get("username")), "nickname", str(rows.get(0).get("nickname")));
    }

    private Map<String, Object> stream(String rtmp, String http, Object key, Object secret) {
        String streamKey = str(key);
        String sec = str(secret);
        String push = streamKey.isBlank() ? "" : rtmp.replaceAll("/$", "") + "/" + streamKey + (sec.isBlank() ? "" : "?secret=" + sec);
        String base = http.replaceAll("/$", "");
        Map<String, Object> play = streamKey.isBlank()
                ? Map.of("flv", "", "hls", "")
                : Map.of("flv", base + "/" + streamKey + ".live.flv", "hls", base + "/" + streamKey + "/hls.m3u8");
        Map<String, Object> item = new LinkedHashMap<>();
        item.put("stream_key", streamKey);
        item.put("secret", sec);
        item.put("push_url", push);
        item.put("play_url", play);
        return item;
    }

    private int count(String sql) {
        Integer n = crud.jdbc().queryForObject(sql, Integer.class);
        return n == null ? 0 : n;
    }

    private static long epoch(Object value) {
        if (value instanceof Number n) {
            long v = n.longValue();
            return String.valueOf(Math.abs(v)).length() > 10 ? v / 1000 : v;
        }
        String text = str(value).trim();
        if (text.isBlank()) return 0;
        try {
            long v = Long.parseLong(text);
            return text.length() > 10 ? v / 1000 : v;
        } catch (NumberFormatException e) {
            try {
                return LocalDateTime.parse(text.replace(" ", "T")).atZone(ZoneId.of("Asia/Shanghai")).toEpochSecond();
            } catch (Exception ignored) {
                return 0;
            }
        }
    }

    private static int num(Object value) {
        if (value instanceof Number n) return n.intValue();
        try { return Integer.parseInt(str(value)); } catch (Exception e) { return 0; }
    }

    private static String str(Object value) {
        return value == null ? "" : String.valueOf(value);
    }

    private static String blank(String value) {
        return value == null ? "" : value;
    }
}
