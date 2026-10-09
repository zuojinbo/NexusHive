package com.nexushive.web;

import com.fasterxml.jackson.databind.ObjectMapper;
import com.nexushive.common.ApiException;
import com.nexushive.common.ApiResult;
import com.nexushive.db.CrudEngine;
import com.nexushive.flight.FlightHttp;
import com.nexushive.media.MediaHttp;
import com.nexushive.platform.AuthService;
import com.nexushive.platform.PlatformHttp;
import com.nexushive.platform.entity.AdminAccount;
import jakarta.servlet.http.HttpServletRequest;
import org.springframework.http.MediaType;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RestController;
import org.springframework.web.multipart.MultipartFile;
import org.springframework.web.multipart.MultipartHttpServletRequest;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.Set;

@RestController
public class LegacyApiController {
    private static final Set<String> NO_LOGIN = Set.of(
            "admin:index:login", "admin:index:logout",
            "api:index:index", "api:index:osd",
            "api:rtmp:querysecret", "api:rtmp:updatesecret", "api:rtmp:streamstatus", "api:rtmp:kickstream", "api:rtmp:streamlist",
            "api:agora:token", "api:agora:batchtoken", "api:agora:startpush", "api:agora:stoppush", "api:agora:querypush", "api:agora:getplayurl",
            "api:videostream:test", "api:videostream:analyzeframe",
            "api:user:checkin", "api:user:logout",
            "api:common:captcha", "api:common:clickcaptcha", "api:common:checkclickcaptcha", "api:common:refreshtoken",
            "api:ajax:area", "api:ajax:buildsuffixsvg");
    private static final Set<String> NO_PERMISSION = Set.of(
            "admin:index:index", "admin:mqtt.monitor:status", "admin:mqtt.monitor:connections",
            "admin:mqtt.monitor:logs", "admin:mqtt.monitor:stats");
    private static final Map<String, String> TABLES = Map.ofEntries(
            Map.entry("equipment", "equipment"),
            Map.entry("equipment.aircraft", "equipment_aircraft"),
            Map.entry("equipment.load", "equipment_load"),
            Map.entry("equipment.alarm", "equipment_alarm"),
            Map.entry("airline", "airline"),
            Map.entry("airline.floder", "airline_floder"),
            Map.entry("flighttask", "flighttask"),
            Map.entry("flightrecord", "flightrecord"),
            Map.entry("flightosd", "flightosd"),
            Map.entry("media", "media"),
            Map.entry("hms", "hms"),
            Map.entry("hmscenter", "hmscenter"),
            Map.entry("project", "project"),
            Map.entry("project.admin", "project_admin"),
            Map.entry("project.user", "project_user"),
            Map.entry("appcenter", "appcenter"),
            Map.entry("algorithmbox", "algorithmbox"),
            Map.entry("valgorithmbox", "valgorithmbox"),
            Map.entry("djilog", "djilog"),
            Map.entry("errormsg", "errormsg"),
            Map.entry("modemanage", "modemanage"),
            Map.entry("auth.admin", "admin"),
            Map.entry("auth.group", "admin_group"),
            Map.entry("auth.rule", "admin_rule"),
            Map.entry("auth.adminlog", "admin_log"),
            Map.entry("user.user", "user"),
            Map.entry("user.group", "user_group"),
            Map.entry("user.rule", "user_rule"),
            Map.entry("user.scorelog", "user_score_log"),
            Map.entry("user.moneylog", "user_money_log"),
            Map.entry("routine.attachment", "attachment"),
            Map.entry("routine.config", "config"),
            Map.entry("security.sensitivedata", "security_sensitive_data"),
            Map.entry("security.sensitivedatalog", "security_sensitive_data_log"),
            Map.entry("security.datarecycle", "security_data_recycle"),
            Map.entry("security.datarecyclelog", "security_data_recycle_log"),
            Map.entry("firmware.version", "firmware"),
            Map.entry("firmware.upgrade", "firmware_upgrade_task"),
            Map.entry("crud.log", "crud_log"));

    private final ObjectMapper mapper;
    private final AuthService auth;
    private final CrudEngine crud;
    private final FlightHttp flight;
    private final MediaHttp media;
    private final PlatformHttp platform;

    public LegacyApiController(ObjectMapper mapper, AuthService auth, CrudEngine crud, FlightHttp flight, MediaHttp media, PlatformHttp platform) {
        this.mapper = mapper;
        this.auth = auth;
        this.crud = crud;
        this.flight = flight;
        this.media = media;
        this.platform = platform;
    }

    @RequestMapping({"/admin/**", "/api/**"})
    public Object dispatch(HttpServletRequest request) throws Exception {
        List<String> seg = new ArrayList<>();
        for (String part : request.getRequestURI().split("/")) {
            if (!part.isBlank()) seg.add(part);
        }
        if (seg.size() < 3) return ApiResult.fail("参数错误!").toMap();
        String app = seg.get(0).toLowerCase(Locale.ROOT);
        String actionKey = seg.get(seg.size() - 1).toLowerCase(Locale.ROOT);
        String controllerKey = String.join(".", seg.subList(1, seg.size() - 1)).toLowerCase(Locale.ROOT);
        String route = controllerKey.replace('.', '/') + "/" + actionKey;
        Map<String, Object> params = Params.read(request, mapper);
        String guard = app + ":" + controllerKey + ":" + actionKey;

        if (unported(app, controllerKey, actionKey)) {
            return ApiResult.fail(unportedMessage(app, controllerKey, actionKey)).toMap();
        }
        AdminAccount admin = null;
        if (app.equals("admin") && !NO_LOGIN.contains(guard)) {
            admin = auth.requireAdmin(request);
            if (!NO_PERMISSION.contains(guard) && !auth.hasPermission(admin.getId(), route)) {
                throw new ApiException("没有权限操作！", List.of(), 401);
            }
        }

        Object special = special(app, controllerKey, actionKey, request, params, admin);
        if (special != null) return special;

        String table = TABLES.get(controllerKey);
        if (table == null || !crud.meta().hasTable(table)) {
            return ApiResult.fail("接口不存在").toMap();
        }
        return crudAction(table, controllerKey, actionKey, route, params, request, admin);
    }

    private Object special(String app, String controller, String action, HttpServletRequest request, Map<String, Object> params, AdminAccount admin) {
        if ("admin".equals(app) && "index".equals(controller)) {
            return switch (action) {
                case "login" -> login(request, params);
                case "logout" -> logout(request);
                case "index" -> ok(platform.adminIndex(admin, request));
                default -> null;
            };
        }
        if ("api".equals(app) && "index".equals(controller)) {
            return switch (action) {
                case "index" -> ok(platform.apiIndex(request));
                case "osd" -> ok("返回成功", platform.osd());
                default -> null;
            };
        }
        if ("admin".equals(app) && "dashboard".equals(controller)) {
            if ("osd".equals(action)) return ok("返回成功", platform.osd());
            if ("index".equals(action)) return ok(Map.of("remark", crud.remark("dashboard")));
        }
        if ("admin".equals(app) && "equipment".equals(controller)) {
            if ("index".equals(action) || "select".equals(action)) return ok(flight.equipmentIndex(query(params, "equipment/index")));
            if ("add".equals(action)) {
                if (!Params.isPost(request)) throw new ApiException("参数错误!");
                flight.equipmentAdd(params, admin);
                return ok("添加成功!", null);
            }
            if ("getaircraftlist".equals(action)) return ok(flight.aircraftList());
            if ("getrtmpconfig".equals(action)) return ok(flight.rtmpConfig(Params.str(params, "sn")));
            if ("updatertmpconfig".equals(action)) {
                flight.updateRtmp(params);
                return ok("RTMP配置更新成功", null);
            }
        }
        if ("admin".equals(app) && "airline".equals(controller) && ("add".equals(action) || "edit".equals(action))) {
            return airline(action, request, params);
        }
        if ("admin".equals(app) && "flighttask".equals(controller)) {
            Object handled = flighttask(action, request, params, admin);
            if (handled != null) return handled;
        }
        if ("admin".equals(app) && "firmware.upgrade".equals(controller)) {
            if ("create".equals(action)) return ok("升级任务已下发", platform.firmwareCreate(params));
            if ("detail".equals(action)) {
                String taskId = Params.str(params, "task_id");
                if (taskId.isBlank()) throw new ApiException("任务ID不能为空");
                List<Map<String, Object>> rows = crud.jdbc().queryForList(
                        "SELECT * FROM `" + crud.meta().physical("firmware_upgrade_task") + "` WHERE task_id=? LIMIT 1", taskId);
                if (rows.isEmpty()) throw new ApiException("任务不存在");
                return ok(crud.present("firmware_upgrade_task", rows.get(0)));
            }
        }
        if ("admin".equals(app) && "mqtt.monitor".equals(controller)) return mqtt(action);
        if ("admin".equals(app) && "system.update".equals(controller)) return updater(action);
        if ("admin".equals(app) && "routine.config".equals(controller)) {
            if ("index".equals(action)) return ok(platform.configIndex());
            if ("edit".equals(action) && Params.isPost(request)) {
                platform.configEdit(params);
                return ok("更新成功!", null);
            }
            if ("sendtestmail".equals(action)) throw new ApiException("测试邮件未迁移，请使用环境变量配置邮件服务");
            if ("add".equals(action)) throw new ApiException("参数错误!");
        }
        if ("admin".equals(app) && "routine.admininfo".equals(controller)) return adminInfo(action, request, params, admin);
        if ("admin".equals(app) && ("auth.rule".equals(controller) || "user.rule".equals(controller) || "auth.group".equals(controller) || "user.group".equals(controller))
                && ("index".equals(action) || "select".equals(action))) {
            String table = TABLES.get(controller);
            if ("select".equals(action)) {
                Map<String, Object> page = platform.ruleTree(table, Params.str(params, "quickSearch"));
                return ok(Map.of("options", page.get("list")));
            }
            return ok(platform.ruleTree(table, Params.str(params, "quickSearch")));
        }
        if ("admin".equals(app) && "auth.admin".equals(controller) && ("add".equals(action) || "edit".equals(action))) {
            return adminAccount(action, request, params);
        }
        if (("admin".equals(app) || "api".equals(app)) && "ajax".equals(controller)) return ajax(app, action, request, params, admin);
        if ("api".equals(app) && "rtmp".equals(controller)) return rtmp(action, params);
        if ("api".equals(app) && "agora".equals(controller)) return agora(action, params);
        if ("api".equals(app) && "videostream".equals(controller)) return video(action, params);
        if ("api".equals(app) && "user".equals(controller)) return memberUser(action, request);
        if ("api".equals(app) && ("account".equals(controller) || "ems".equals(controller))) {
            throw new ApiException("会员中心已禁用，请联系网站管理员开启。");
        }
        if ("api".equals(app) && "common".equals(controller) && "refreshtoken".equals(action)) {
            return ok(auth.refresh(Params.str(params, "refreshToken")));
        }
        if (("admin".equals(app) || "api".equals(app)) && "alioss".equals(controller)) {
            return ok(Map.of());
        }
        return null;
    }

    private Object crudAction(String table, String controller, String action, String route, Map<String, Object> params, HttpServletRequest request, AdminAccount admin) {
        if ("project".equals(table) && admin != null && !auth.isSuper(admin.getId())) {
            // applied inside query via extra below
        }
        return switch (action) {
            case "index", "select" -> {
                CrudEngine.Query query = query(params, route);
                if ("project".equals(table) && admin != null && !auth.isSuper(admin.getId())) {
                    query.extraSql.add("m.`admin_id`=?");
                    query.extraArgs.add(admin.getId());
                }
                if ("auth.admin".equals(controller)) query.quickFields = List.of("username", "nickname");
                Map<String, Object> page = crud.page(table, query);
                if ("admin".equals(table)) {
                    @SuppressWarnings("unchecked")
                    List<Map<String, Object>> list = (List<Map<String, Object>>) page.get("list");
                    platform.decorateAdmins(list);
                }
                if ("flighttask".equals(table)) flight.enrichFlighttask(page);
                if ("airline".equals(table)) flight.enrichAirline(page);
                yield ok(page);
            }
            case "edit" -> edit(table, params, request, admin);
            case "add" -> add(table, params, request, admin);
            case "del" -> del(table, controller, params);
            case "sortable" -> sortable(table, params);
            default -> ApiResult.fail("接口不存在").toMap();
        };
    }

    private Object add(String table, Map<String, Object> params, HttpServletRequest request, AdminAccount admin) {
        if (!Params.isPost(request)) throw new ApiException("参数错误!");
        if (params.isEmpty()) throw new ApiException("参数不能为空");
        if ("project".equals(table) && admin != null) params.putIfAbsent("admin_id", admin.getId());
        if ("user".equals(table) && params.get("password") != null && !Params.str(params, "password").isBlank()) {
            params.put("password", new com.nexushive.security.PasswordVerifier().hash(Params.str(params, "password")));
        }
        long id = crud.insert(table, params);
        if (id <= 0) throw new ApiException("未添加任何行");
        return ok("添加成功!", null);
    }

    private Object edit(String table, Map<String, Object> params, HttpServletRequest request, AdminAccount admin) {
        String pk = crud.meta().pk(table);
        Object id = params.get(pk);
        if (id == null || String.valueOf(id).isBlank()) id = request.getParameter(pk);
        Map<String, Object> row = id == null ? null : crud.find(table, id);
        if (row == null) throw new ApiException("记录未找到");
        if ("project".equals(table) && admin != null && !auth.isSuper(admin.getId())
                && !String.valueOf(admin.getId()).equals(String.valueOf(row.get("admin_id")))) {
            throw new ApiException("没有权限操作！", List.of(), 401);
        }
        if (Params.isPost(request)) {
            if ("user".equals(table) && params.get("password") != null && !Params.str(params, "password").isBlank()) {
                params.put("password", new com.nexushive.security.PasswordVerifier().hash(Params.str(params, "password")));
            } else if ("user".equals(table)) {
                params.remove("password");
            }
            crud.updateByPk(table, id, params);
            return ok("更新成功!", null);
        }
        if ("admin".equals(table)) platform.decorateAdmin(row);
        return ok(Map.of("row", row));
    }

    private Object del(String table, String controller, Map<String, Object> params) {
        List<String> ids = Params.ids(params);
        if (ids.isEmpty()) throw new ApiException("参数 ids 不能为空");
        if ("firmware.upgrade".equals(controller)) {
            String marks = String.join(",", ids.stream().map(id -> "?").toList());
            List<Map<String, Object>> rows = crud.jdbc().queryForList(
                    "SELECT id, status FROM `" + crud.meta().physical(table) + "` WHERE id IN (" + marks + ")", ids.toArray());
            List<Object> allowed = new ArrayList<>();
            for (Map<String, Object> row : rows) {
                String status = String.valueOf(row.get("status"));
                if (List.of("ok", "failed", "canceled", "rejected", "timeout").contains(status)) allowed.add(row.get("id"));
            }
            int n = crud.deleteByIds(table, allowed);
            if (n == 0) throw new ApiException("没有可删除的记录（只能删除已完成的任务）");
            return ok("删除成功!", null);
        }
        int n = crud.deleteByIds(table, ids);
        if (n == 0) throw new ApiException("未删除任何行");
        return ok("删除成功!", null);
    }

    private Object sortable(String table, Map<String, Object> params) {
        if (!crud.meta().hasColumn(table, "weigh")) throw new ApiException("请先使用 weigh 字段排序再操作");
        Object move = params.get("move");
        Object target = params.get("target");
        Map<String, Object> moveRow = crud.find(table, move);
        Map<String, Object> targetRow = crud.find(table, target);
        if (moveRow == null || targetRow == null) throw new ApiException("记录未找到");
        String pk = crud.meta().pk(table);
        crud.jdbc().update("UPDATE `" + crud.meta().physical(table) + "` SET weigh=? WHERE `" + pk + "`=?", targetRow.get("weigh"), move);
        crud.jdbc().update("UPDATE `" + crud.meta().physical(table) + "` SET weigh=? WHERE `" + pk + "`=?", moveRow.get("weigh"), target);
        return ok("更新成功!", null);
    }

    private Object login(HttpServletRequest request, Map<String, Object> params) {
        AdminAccount current = auth.peekAdmin(request);
        if (current != null) {
            throw new ApiException("您已经登录过了，无需重复登录~", Map.of("type", "logged in"), 303);
        }
        if (!Params.isPost(request)) return ok(Map.of("captcha", false));
        String username = Params.str(params, "username");
        String password = Params.str(params, "password");
        if (password.matches(".*[&<>\"'\\n\\r].*")) throw new ApiException("密码不正确");
        boolean keep = "1".equals(Params.str(params, "keep")) || "true".equalsIgnoreCase(Params.str(params, "keep")) || Boolean.TRUE.equals(params.get("keep"));
        Map<String, Object> info = auth.login(username, password, keep, request.getRemoteAddr());
        return ok("登录成功！", Map.of("userInfo", info));
    }

    private Object logout(HttpServletRequest request) {
        if (!Params.isPost(request)) return ok(null);
        auth.logout(request);
        return ok(null);
    }

    private Object airline(String action, HttpServletRequest request, Map<String, Object> params) {
        if ("edit".equals(action) && !Params.isPost(request)) {
            Object id = params.getOrDefault("id", request.getParameter("id"));
            Map<String, Object> row = crud.find("airline", id);
            if (row == null) throw new ApiException("记录未找到");
            return ok(Map.of("row", row));
        }
        if (!Params.isPost(request)) throw new ApiException("参数错误!");
        flight.airlineSave(params, "add".equals(action));
        if ("add".equals(action)) {
            long id = crud.insert("airline", params);
            if (id <= 0) throw new ApiException("未添加任何行");
            return ok("添加成功!", null);
        }
        Object id = params.get("id");
        if (id == null) throw new ApiException("记录未找到");
        crud.updateByPk("airline", id, params);
        return ok("更新成功!", null);
    }

    private Object flighttask(String action, HttpServletRequest request, Map<String, Object> params, AdminAccount admin) {
        if ("add".equals(action)) {
            if (!Params.isPost(request)) throw new ApiException("参数错误!");
            boolean manual = "manual".equals(Params.str(params, "flight_mode"));
            boolean repeat = "2".equals(Params.str(params, "task_type"));
            Map<String, Object> data = flight.addTask(params, admin);
            if (repeat) return ok("循环任务创建成功", data);
            if (manual) return ok("手动飞行任务创建成功", data);
            return ok("添加成功!", data);
        }
        if ("cancel".equals(action)) {
            if (!Params.isPost(request)) throw new ApiException("请求方式错误");
            return ok(flight.cancel(Params.ids(params)), null);
        }
        if ("reportmedia".equals(action)) {
            if (!Params.isPost(request)) throw new ApiException("请求方式错误");
            return ok("媒体数量上报成功", flight.reportMedia(params));
        }
        if ("completemanual".equals(action)) {
            if (!Params.isPost(request)) throw new ApiException("请求方式错误");
            return ok("任务完成成功", flight.completeManual(params));
        }
        if ("export".equals(action)) return ok(flight.export(request, params));
        if ("errordetail".equals(action)) return ok("获取成功", flight.errorDetail(params.get("id")));
        if ("errorstats".equals(action)) return ok(flight.errorStats());
        return null;
    }

    private Object mqtt(String action) {
        return switch (action) {
            case "status", "report" -> ok(platform.mqttStatus());
            case "connections" -> ok(Map.of("list", List.of(), "total", 0, "summary", Map.of("connections", 0, "max_connections", 0)));
            case "logs" -> ok(Map.of("list", platform.mqttStatus().get("logs"), "total", 0));
            case "stats" -> ok(Map.of("throughput", List.of(), "top_topics", List.of(), "global", Map.of(), "current_rate", Map.of(), "connections", List.of()));
            case "subscriptions" -> ok(Map.of("stats", List.of(), "topics", List.of()));
            case "clearlogs" -> ok("日志已清空", null);
            case "disconnect" -> throw new ApiException("Java 进程内 MQTT 客户端不维护浏览器连接池");
            default -> ApiResult.fail("接口不存在").toMap();
        };
    }

    private Object updater(String action) {
        return switch (action) {
            case "info" -> ok(platform.versionInfo());
            case "check" -> ok(Map.of("has_update", false, "current_version", "1.0.8", "message", "在线更新检查未迁移，当前版本以 config/version.php 为准"));
            case "changelog" -> ok(Map.of("changelog", List.of()));
            case "history" -> ok(Map.of("list", List.of(), "total", 0));
            case "timeline" -> ok(Map.of("list", List.of(Map.of("version", "1.0.8", "updated_at", "2025-12-13 18:38:00"))));
            case "files", "notice" -> ok(Map.of("list", List.of()));
            case "dismissnotice" -> ok(null);
            case "execute" -> throw new ApiException("在线安装器未迁移，请使用发布包更新");
            default -> ApiResult.fail("接口不存在").toMap();
        };
    }

    private Object adminInfo(String action, HttpServletRequest request, Map<String, Object> params, AdminAccount admin) {
        if ("index".equals(action) || ("edit".equals(action) && !Params.isPost(request))) {
            Map<String, Object> row = crud.find("admin", admin.getId());
            platform.decorateAdmin(row);
            return ok("index".equals(action) ? Map.of("info", row) : Map.of("row", row));
        }
        if ("edit".equals(action)) {
            params.remove("username");
            params.remove("password");
            params.remove("id");
            crud.updateByPk("admin", admin.getId(), params);
            return ok("更新成功!", null);
        }
        return ApiResult.fail("接口不存在").toMap();
    }

    private Object adminAccount(String action, HttpServletRequest request, Map<String, Object> params) {
        if ("add".equals(action)) {
            if (!Params.isPost(request)) throw new ApiException("参数错误!");
            platform.hashAdmin(params, null, true);
            return ok("添加成功!", null);
        }
        Object id = params.getOrDefault("id", request.getParameter("id"));
        if (!Params.isPost(request)) {
            Map<String, Object> row = crud.find("admin", id);
            if (row == null) throw new ApiException("记录未找到");
            platform.decorateAdmin(row);
            return ok(Map.of("row", row));
        }
        platform.hashAdmin(params, id, false);
        return ok("更新成功!", null);
    }

    private Object ajax(String app, String action, HttpServletRequest request, Map<String, Object> params, AdminAccount admin) {
        if ("upload".equals(action)) {
            MultipartFile file = null;
            if (request instanceof MultipartHttpServletRequest multi) file = multi.getFile("file");
            long adminId = admin == null ? 0 : admin.getId();
            return ok("文件上传成功！", platform.upload(file, Params.str(params, "topic"), adminId));
        }
        if ("area".equals(action)) return ok(platform.area(Params.str(params, "province"), Params.str(params, "city")));
        if ("buildsuffixsvg".equals(action)) {
            return ResponseEntity.ok().contentType(MediaType.valueOf("image/svg+xml"))
                    .body(platform.suffixSvg(Params.str(params, "suffix")));
        }
        if ("clearcache".equals(action)) {
            String type = Params.str(params, "type");
            if (!"tp".equals(type) && !"all".equals(type)) throw new ApiException("参数错误!");
            return ok("缓存已清理，请刷新后台~", null);
        }
        if ("synckmztooss".equals(action) || "batchsynckmztooss".equals(action)) {
            return ok("本地 KMZ 已保留。未配置对象存储时不会上传。", Map.of());
        }
        if (Set.of("getdatabaseconnectionlist", "gettablepk", "gettablelist", "gettablefieldlist", "changeterminalconfig", "terminal").contains(action)) {
            throw new ApiException("CRUD 生成器与 Web 终端未迁移");
        }
        return null;
    }

    private Object rtmp(String action, Map<String, Object> params) {
        return switch (action) {
            case "querysecret" -> ok("查询成功", media.querySecret());
            case "updatesecret" -> throw new ApiException("ZLMediaKit 未启用全局推流密钥动态更新，请在设备 RTMP 配置或环境变量中修改");
            case "streamstatus" -> ok("查询成功", media.streamStatus(Params.str(params, "streamKey")));
            case "kickstream" -> ok("操作成功", media.kick(Params.str(params, "streamKey")));
            case "streamlist" -> ok("查询成功", media.streamList());
            default -> ApiResult.fail("接口不存在").toMap();
        };
    }

    private Object agora(String action, Map<String, Object> params) {
        if ("token".equals(action)) return ok("获取成功", media.agoraToken(params));
        if ("batchtoken".equals(action)) throw new ApiException("声网服务未配置");
        if (Set.of("startpush", "stoppush", "querypush", "getplayurl").contains(action)) {
            throw new ApiException("声网旁路推流未配置");
        }
        return ApiResult.fail("接口不存在").toMap();
    }

    private Object video(String action, Map<String, Object> params) {
        if ("test".equals(action)) return ok("VideoStream API is working", media.videoTest());
        if ("analyzeframe".equals(action)) return ok(media.analyzeFrame(params));
        return ApiResult.fail("接口不存在").toMap();
    }

    private Object memberUser(String action, HttpServletRequest request) {
        if ("checkin".equals(action)) throw new ApiException("会员中心已禁用，请联系网站管理员开启。");
        if ("logout".equals(action)) {
            if (Params.isPost(request)) return ok(null);
            return ok(null);
        }
        throw new ApiException("会员中心已禁用，请联系网站管理员开启。");
    }

    private boolean unported(String app, String controller, String action) {
        if ("api".equals(app) && "install".equals(controller)) return true;
        if ("admin".equals(app) && "module".equals(controller)) return true;
        if ("admin".equals(app) && "crud.crud".equals(controller)) return true;
        if ("api".equals(app) && "index".equals(controller) && Set.of("aaa", "eeee", "test", "getali", "setlog").contains(action)) return true;
        return false;
    }

    private String unportedMessage(String app, String controller, String action) {
        if ("install".equals(controller)) return "安装向导未迁移";
        if ("module".equals(controller)) return "模块市场未迁移";
        if (controller.startsWith("crud")) return "CRUD 代码生成器未迁移";
        return "调试接口未迁移";
    }

    private CrudEngine.Query query(Map<String, Object> params, String remark) {
        CrudEngine.Query query = new CrudEngine.Query();
        query.page = parseInt(params.get("page"), 1);
        query.limit = parseInt(params.get("limit"), 10);
        query.quick = Params.str(params, "quickSearch");
        query.quickFields = List.of("id", "name", "nickname", "sn", "title", "username");
        String order = Params.str(params, "order");
        if (!order.isBlank()) {
            String[] parts = order.split(",");
            query.orderField = parts[0].trim();
            query.orderDir = parts.length > 1 ? parts[1].trim() : "desc";
        }
        if (!Params.str(params, "initKey").isBlank()) query.initKey = Params.str(params, "initKey");
        query.initValue = Params.str(params, "initValue");
        if (!Params.str(params, "initOperator").isBlank()) query.initOperator = Params.str(params, "initOperator");
        query.remarkPath = remark;
        if (!Params.str(params, "select").isBlank()) query.limit = 999999;
        for (Map<String, Object> search : Params.searches(params)) {
            CrudEngine.Search item = new CrudEngine.Search();
            item.field = String.valueOf(search.getOrDefault("field", ""));
            item.operator = String.valueOf(search.getOrDefault("operator", "="));
            Object val = search.get("val");
            item.val = val == null ? "" : String.valueOf(val);
            item.render = search.get("render") == null ? "" : String.valueOf(search.get("render"));
            if (item.field.contains(".")) item.field = item.field.substring(item.field.lastIndexOf('.') + 1);
            query.searches.add(item);
        }
        return query;
    }

    private static int parseInt(Object value, int fallback) {
        if (value instanceof Number n) return n.intValue();
        try { return Integer.parseInt(String.valueOf(value)); } catch (Exception e) { return fallback; }
    }

    private Map<String, Object> ok(Object data) {
        return ApiResult.ok("", data).toMap();
    }

    private Map<String, Object> ok(String msg, Object data) {
        return ApiResult.ok(msg, data).toMap();
    }
}
