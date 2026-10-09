package com.nexushive.platform;

import com.baomidou.mybatisplus.core.conditions.query.QueryWrapper;
import com.nexushive.common.ApiException;
import com.nexushive.config.AppProperties;
import com.nexushive.db.CrudEngine;
import com.nexushive.platform.entity.AdminAccount;
import com.nexushive.platform.mapper.AdminMapper;
import com.nexushive.security.PasswordVerifier;
import com.nexushive.security.TokenHasher;
import jakarta.servlet.http.HttpServletRequest;
import org.springframework.jdbc.core.JdbcTemplate;
import org.springframework.stereotype.Service;

import java.time.Instant;
import java.time.ZoneId;
import java.time.format.DateTimeFormatter;
import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.UUID;

@Service
public class AuthService {
    private static final DateTimeFormatter TS = DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss").withZone(ZoneId.of("Asia/Shanghai"));
    private final AdminMapper adminMapper;
    private final JdbcTemplate jdbc;
    private final AppProperties props;
    private final CrudEngine crud;
    private final PasswordVerifier passwords = new PasswordVerifier();
    private final TokenHasher hasher;

    public AuthService(AdminMapper adminMapper, JdbcTemplate jdbc, AppProperties props, CrudEngine crud) {
        this.adminMapper = adminMapper;
        this.jdbc = jdbc;
        this.props = props;
        this.crud = crud;
        this.hasher = new TokenHasher(props.getTokenKey());
    }

    public AdminAccount findByUsername(String username) {
        return adminMapper.selectOne(new QueryWrapper<AdminAccount>().eq("username", username).last("LIMIT 1"));
    }

    public AdminAccount peekAdmin(HttpServletRequest request) {
        String token = tokenFrom(request, "ba", "token");
        if (token == null || token.isBlank()) return null;
        Map<String, Object> row = lookupToken(token, "admin", false);
        if (row == null) return null;
        Object expire = row.get("expire_time");
        if (expire instanceof Number n && n.longValue() > 0 && n.longValue() <= Instant.now().getEpochSecond()) {
            return null;
        }
        return adminMapper.selectById(((Number) row.get("user_id")).longValue());
    }

    public AdminAccount requireAdmin(HttpServletRequest request) {
        String token = tokenFrom(request, "ba", "token");
        if (token == null || token.isBlank()) {
            throw new ApiException("请先登录！", Map.of("type", "need login"), 303);
        }
        Map<String, Object> row = lookupToken(token, "admin", true);
        if (row == null) {
            throw new ApiException("请先登录！", Map.of("type", "need login"), 303);
        }
        AdminAccount admin = adminMapper.selectById(((Number) row.get("user_id")).longValue());
        if (admin == null || "disable".equals(admin.getStatus())) {
            throw new ApiException("请先登录！", Map.of("type", "need login"), 303);
        }
        return admin;
    }

    public Map<String, Object> refresh(String refreshPlain) {
        if (refreshPlain == null || refreshPlain.isBlank()) {
            throw new ApiException("登录过期，请重新登录。");
        }
        Map<String, Object> row = lookupRefresh(refreshPlain);
        if (row == null) {
            throw new ApiException("登录过期，请重新登录。");
        }
        String type = String.valueOf(row.get("type"));
        String issued = type.startsWith("admin") ? "admin" : "user";
        long keep = "admin".equals(issued) ? props.getTokenKeepSeconds() : props.getTokenKeepSeconds();
        String token = UUID.randomUUID().toString();
        storeToken(token, issued, ((Number) row.get("user_id")).longValue(), Instant.now().getEpochSecond() + keep);
        Map<String, Object> data = new LinkedHashMap<>();
        data.put("type", type);
        data.put("token", token);
        return data;
    }

    public Map<String, Object> login(String username, String password, boolean keep, String ip) {
        if (username == null || username.length() < 3 || username.length() > 30) {
            throw new ApiException("用户名不正确");
        }
        if (password == null || password.length() < 6 || password.length() > 32) {
            throw new ApiException("密码不正确");
        }
        AdminAccount admin = findByUsername(username);
        if (admin == null) {
            throw new ApiException("用户名不正确");
        }
        if ("disable".equals(admin.getStatus())) {
            throw new ApiException("帐户已禁用");
        }
        long now = Instant.now().getEpochSecond();
        int failures = admin.getLoginFailure() == null ? 0 : admin.getLoginFailure();
        Long last = admin.getLastLoginTime();
        if (failures > 0 && last != null && now - last >= 86400) {
            failures = 0;
        }
        if (failures >= 10) {
            throw new ApiException("登录失败次数超限，请在1天后再试");
        }
        if (!passwords.verify(password, admin.getPassword(), admin.getSalt())) {
            jdbc.update("UPDATE `" + props.table("admin") + "` SET login_failure=?, last_login_time=?, last_login_ip=? WHERE id=?",
                    failures + 1, now, ip, admin.getId());
            throw new ApiException("密码不正确");
        }
        String token = UUID.randomUUID().toString();
        String refresh = keep ? UUID.randomUUID().toString() : "";
        jdbc.update("UPDATE `" + props.table("admin") + "` SET login_failure=0, last_login_time=?, last_login_ip=? WHERE id=?",
                now, ip, admin.getId());
        storeToken(token, "admin", admin.getId(), now + props.getTokenKeepSeconds());
        if (keep) {
            storeToken(refresh, "admin-refresh", admin.getId(), now + props.getRefreshKeepSeconds());
        }
        admin.setLastLoginTime(now);
        Map<String, Object> info = adminInfo(admin, false);
        info.put("token", token);
        info.put("refresh_token", refresh);
        return info;
    }

    public void logout(HttpServletRequest request) {
        String refresh = request.getParameter("refreshToken");
        if (refresh != null && !refresh.isBlank()) {
            jdbc.update("DELETE FROM `" + props.table("token") + "` WHERE token=?", hasher.hash(refresh));
        }
        String token = tokenFrom(request, "ba", "token");
        if (token != null && !token.isBlank()) {
            jdbc.update("DELETE FROM `" + props.table("token") + "` WHERE token=?", hasher.hash(token));
        }
    }

    public boolean isSuper(long adminId) {
        List<String> rules = jdbc.query(
                "SELECT g.rules FROM `" + props.table("admin_group_access") + "` a JOIN `" + props.table("admin_group")
                        + "` g ON a.group_id=g.id WHERE a.uid=? AND g.status='1'",
                (rs, i) -> rs.getString(1), adminId);
        for (String rule : rules) {
            if (rule != null) {
                for (String part : rule.split(",")) {
                    if ("*".equals(part.trim())) return true;
                }
            }
        }
        return false;
    }

    public boolean hasPermission(long adminId, String routePath) {
        if (isSuper(adminId)) return true;
        String needle = routePath.toLowerCase();
        List<String> names = jdbc.query(
                "SELECT r.name FROM `" + props.table("admin_group_access") + "` a "
                        + "JOIN `" + props.table("admin_group") + "` g ON a.group_id=g.id "
                        + "JOIN `" + props.table("admin_rule") + "` r ON FIND_IN_SET(r.id, g.rules) "
                        + "WHERE a.uid=? AND g.status='1' AND r.status='1'",
                (rs, i) -> rs.getString(1), adminId);
        for (String name : names) {
            if (name != null && name.equalsIgnoreCase(needle)) return true;
        }
        return false;
    }

    public List<Map<String, Object>> menus(long adminId) {
        boolean superAdmin = isSuper(adminId);
        List<Map<String, Object>> rules;
        if (superAdmin) {
            rules = jdbc.queryForList("SELECT * FROM `" + props.table("admin_rule") + "` WHERE status='1' ORDER BY weigh DESC, id ASC");
        } else {
            rules = jdbc.queryForList(
                    "SELECT DISTINCT r.* FROM `" + props.table("admin_group_access") + "` a "
                            + "JOIN `" + props.table("admin_group") + "` g ON a.group_id=g.id "
                            + "JOIN `" + props.table("admin_rule") + "` r ON FIND_IN_SET(r.id, g.rules) "
                            + "WHERE a.uid=? AND g.status='1' AND r.status='1' ORDER BY r.weigh DESC, r.id ASC",
                    adminId);
        }
        Map<Long, List<Map<String, Object>>> byPid = new LinkedHashMap<>();
        for (Map<String, Object> rule : rules) {
            Map<String, Object> item = new LinkedHashMap<>(rule);
            item.remove("remark");
            item.remove("status");
            item.remove("weigh");
            item.remove("update_time");
            item.remove("create_time");
            Object keepalive = item.get("keepalive");
            if (keepalive != null && !String.valueOf(keepalive).isBlank() && !"0".equals(String.valueOf(keepalive))) {
                item.put("keepalive", item.get("name"));
            }
            long pid = item.get("pid") == null ? 0 : ((Number) item.get("pid")).longValue();
            byPid.computeIfAbsent(pid, k -> new ArrayList<>()).add(item);
        }
        return children(byPid, 0L);
    }

    private List<Map<String, Object>> children(Map<Long, List<Map<String, Object>>> byPid, long pid) {
        List<Map<String, Object>> list = byPid.getOrDefault(pid, List.of());
        for (Map<String, Object> item : list) {
            long id = ((Number) item.get("id")).longValue();
            if (byPid.containsKey(id)) {
                item.put("children", children(byPid, id));
            }
        }
        return list;
    }

    public Map<String, Object> adminInfo(AdminAccount admin, boolean withSuper) {
        Map<String, Object> info = new LinkedHashMap<>();
        info.put("id", admin.getId());
        info.put("username", admin.getUsername());
        info.put("nickname", admin.getNickname());
        String avatar = admin.getAvatar();
        if (avatar == null || avatar.isBlank()) avatar = "/static/images/avatar.png";
        info.put("avatar", avatar);
        info.put("last_login_time", admin.getLastLoginTime() == null || admin.getLastLoginTime() == 0
                ? "" : TS.format(Instant.ofEpochSecond(admin.getLastLoginTime())));
        if (withSuper) info.put("super", isSuper(admin.getId()));
        return info;
    }

    public String configValue(String name, String fallback) {
        if (!crud.meta().hasTable("config")) return fallback;
        List<String> rows = jdbc.query("SELECT value FROM `" + props.table("config") + "` WHERE name=? LIMIT 1",
                (rs, i) -> rs.getString(1), name);
        if (rows.isEmpty() || rows.get(0) == null || rows.get(0).isBlank()) return fallback;
        return rows.get(0);
    }

    public String tokenFrom(HttpServletRequest request, String... names) {
        String joined = String.join("", names);
        String dashed = String.join("-", names);
        String[] candidates = {joined, dashed, joined.toLowerCase(), dashed.toLowerCase()};
        for (String name : candidates) {
            String header = request.getHeader(name);
            if (header != null && !header.isBlank()) return header.trim();
            String param = request.getParameter(name);
            if (param != null && !param.isBlank()) return param.trim();
        }
        String http = request.getHeader("HTTP_" + joined.toUpperCase());
        if (http != null && !http.isBlank()) return http.trim();
        return null;
    }

    private Map<String, Object> lookupToken(String plain, String type, boolean expireAs409) {
        List<Map<String, Object>> rows = jdbc.queryForList(
                "SELECT * FROM `" + props.table("token") + "` WHERE token=? AND type=? LIMIT 1",
                hasher.hash(plain), type);
        if (rows.isEmpty()) return null;
        Map<String, Object> row = rows.get(0);
        Object expire = row.get("expire_time");
        if (expire instanceof Number n && n.longValue() > 0 && n.longValue() <= Instant.now().getEpochSecond()) {
            if (expireAs409) {
                throw new ApiException("登录态过期，请重新登录！", List.of(), 409);
            }
            return null;
        }
        return row;
    }

    private Map<String, Object> lookupRefresh(String plain) {
        List<Map<String, Object>> rows = jdbc.queryForList(
                "SELECT * FROM `" + props.table("token") + "` WHERE token=? AND type IN ('admin-refresh','user-refresh') LIMIT 1",
                hasher.hash(plain));
        if (rows.isEmpty()) return null;
        Map<String, Object> row = rows.get(0);
        Object expire = row.get("expire_time");
        if (expire instanceof Number n && n.longValue() > 0 && n.longValue() < Instant.now().getEpochSecond()) {
            return null;
        }
        return row;
    }

    private void storeToken(String plain, String type, long userId, long expire) {
        jdbc.update("INSERT INTO `" + props.table("token") + "` (token, type, user_id, create_time, expire_time) VALUES (?,?,?,?,?)",
                hasher.hash(plain), type, userId, Instant.now().getEpochSecond(), expire);
    }
}
