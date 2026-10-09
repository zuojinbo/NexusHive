package com.nexushive.db;

import org.springframework.jdbc.core.JdbcTemplate;
import org.springframework.jdbc.support.GeneratedKeyHolder;
import org.springframework.jdbc.support.KeyHolder;
import org.springframework.stereotype.Service;

import java.sql.PreparedStatement;
import java.sql.Statement;
import java.time.Instant;
import java.time.ZoneId;
import java.time.format.DateTimeFormatter;
import java.util.ArrayList;
import java.util.Collection;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.Set;

@Service
public class CrudEngine {
    private static final DateTimeFormatter TS = DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss").withZone(ZoneId.of("Asia/Shanghai"));
    private static final Set<String> OPS = Set.of(
            "=", "<>", "LIKE", "NOT LIKE", ">", ">=", "<", "<=",
            "IN", "NOT IN", "FIND_IN_SET", "NULL", "NOT NULL", "BETWEEN", "NOT BETWEEN");

    private final JdbcTemplate jdbc;
    private final SchemaMeta meta;

    public CrudEngine(JdbcTemplate jdbc, SchemaMeta meta) {
        this.jdbc = jdbc;
        this.meta = meta;
    }

    public JdbcTemplate jdbc() { return jdbc; }
    public SchemaMeta meta() { return meta; }

    public Map<String, Object> page(String logical, Query query) {
        String table = meta.physical(logical);
        String pk = meta.pk(logical);
        List<Object> args = new ArrayList<>();
        String where = buildWhere(logical, "m", query, args);
        String order = buildOrder(logical, query, pk);
        int limit = query.limit <= 0 ? 10 : Math.min(query.limit, 999999);
        int page = Math.max(query.page, 1);
        int offset = (page - 1) * limit;
        String countSql = "SELECT COUNT(*) FROM `" + table + "` m" + where;
        Long total = jdbc.queryForObject(countSql, Long.class, args.toArray());
        List<Object> pageArgs = new ArrayList<>(args);
        pageArgs.add(limit);
        pageArgs.add(offset);
        String sql = "SELECT m.* FROM `" + table + "` m" + where + order + " LIMIT ? OFFSET ?";
        List<Map<String, Object>> list = jdbc.queryForList(sql, pageArgs.toArray());
        list.replaceAll(row -> present(logical, row));
        Map<String, Object> data = new LinkedHashMap<>();
        data.put("list", list);
        data.put("total", total == null ? 0 : total);
        data.put("remark", remark(query.remarkPath));
        return data;
    }

    public Map<String, Object> find(String logical, Object id) {
        String table = meta.physical(logical);
        String pk = meta.pk(logical);
        meta.requireColumn(logical, pk);
        List<Map<String, Object>> rows = jdbc.queryForList(
                "SELECT * FROM `" + table + "` WHERE `" + pk + "` = ? LIMIT 1", id);
        if (rows.isEmpty()) {
            return null;
        }
        return present(logical, rows.get(0));
    }

    public long insert(String logical, Map<String, Object> data) {
        String table = meta.physical(logical);
        Map<String, Object> clean = filterWritable(logical, data, true);
        long now = Instant.now().getEpochSecond();
        if (meta.hasColumn(logical, "create_time") && !clean.containsKey("create_time") && !meta.isDateTime(logical, "create_time")) {
            clean.put("create_time", now);
        }
        if (meta.hasColumn(logical, "update_time") && !clean.containsKey("update_time") && !meta.isDateTime(logical, "update_time")) {
            clean.put("update_time", now);
        }
        if (clean.isEmpty()) {
            throw new IllegalArgumentException("没有可写入的字段");
        }
        StringBuilder cols = new StringBuilder();
        StringBuilder marks = new StringBuilder();
        List<Object> args = new ArrayList<>();
        for (Map.Entry<String, Object> e : clean.entrySet()) {
            if (!cols.isEmpty()) {
                cols.append(',');
                marks.append(',');
            }
            cols.append('`').append(e.getKey()).append('`');
            marks.append('?');
            args.add(e.getValue());
        }
        String sql = "INSERT INTO `" + table + "` (" + cols + ") VALUES (" + marks + ")";
        KeyHolder keys = new GeneratedKeyHolder();
        jdbc.update(con -> {
            PreparedStatement ps = con.prepareStatement(sql, Statement.RETURN_GENERATED_KEYS);
            for (int i = 0; i < args.size(); i++) {
                ps.setObject(i + 1, args.get(i));
            }
            return ps;
        }, keys);
        Number key = keys.getKey();
        return key == null ? 0 : key.longValue();
    }

    public int updateByPk(String logical, Object id, Map<String, Object> data) {
        String table = meta.physical(logical);
        String pk = meta.pk(logical);
        Map<String, Object> clean = filterWritable(logical, data, false);
        clean.remove(pk);
        if (meta.hasColumn(logical, "update_time") && !meta.isDateTime(logical, "update_time")) {
            clean.put("update_time", Instant.now().getEpochSecond());
        }
        if (clean.isEmpty()) {
            return 0;
        }
        StringBuilder set = new StringBuilder();
        List<Object> args = new ArrayList<>();
        for (Map.Entry<String, Object> e : clean.entrySet()) {
            if (!set.isEmpty()) set.append(',');
            set.append('`').append(e.getKey()).append("`=?");
            args.add(e.getValue());
        }
        args.add(id);
        return jdbc.update("UPDATE `" + table + "` SET " + set + " WHERE `" + pk + "`=?", args.toArray());
    }

    public int deleteByIds(String logical, Collection<?> ids) {
        if (ids == null || ids.isEmpty()) return 0;
        String table = meta.physical(logical);
        String pk = meta.pk(logical);
        StringBuilder marks = new StringBuilder();
        List<Object> args = new ArrayList<>();
        for (Object id : ids) {
            if (!marks.isEmpty()) marks.append(',');
            marks.append('?');
            args.add(id);
        }
        return jdbc.update("DELETE FROM `" + table + "` WHERE `" + pk + "` IN (" + marks + ")", args.toArray());
    }

    public Map<String, Object> present(String logical, Map<String, Object> row) {
        Map<String, Object> out = new LinkedHashMap<>();
        row.forEach((k, v) -> out.put(k, normalize(v)));
        if ("flighttask".equals(logical)) {
            formatUnix(out, "execute_time");
            formatUnix(out, "end_time");
        }
        if ("admin".equals(logical)) {
            formatUnix(out, "last_login_time");
        }
        return out;
    }

    private void formatUnix(Map<String, Object> row, String field) {
        Object v = row.get(field);
        if (v instanceof Number n && n.longValue() > 0) {
            row.put(field, TS.format(Instant.ofEpochSecond(n.longValue())));
        }
    }

    private Object normalize(Object v) {
        if (v instanceof byte[] bytes) {
            return new String(bytes);
        }
        if (v instanceof java.sql.Timestamp ts) {
            return TS.format(ts.toInstant());
        }
        if (v instanceof java.time.LocalDateTime ldt) {
            return ldt.format(DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss"));
        }
        if (v instanceof java.sql.Date date) {
            return date.toLocalDate().toString();
        }
        return v;
    }

    private Map<String, Object> filterWritable(String logical, Map<String, Object> data, boolean insert) {
        Map<String, Object> clean = new LinkedHashMap<>();
        String pk = meta.pk(logical);
        for (Map.Entry<String, Object> e : data.entrySet()) {
            String key = e.getKey();
            if (!key.matches("[A-Za-z0-9_]+") || !meta.hasColumn(logical, key)) continue;
            if (insert && key.equals(pk)) continue;
            if ("create_time".equals(key) || "update_time".equals(key)) continue;
            Object value = e.getValue();
            if (value instanceof List || value instanceof Map) {
                value = com.fasterxml.jackson.databind.json.JsonMapper.builder().build().valueToTree(value).toString();
            }
            clean.put(key, value);
        }
        return clean;
    }

    private String buildWhere(String logical, String alias, Query query, List<Object> args) {
        StringBuilder sb = new StringBuilder();
        List<String> parts = new ArrayList<>();
        if (query.quick != null && !query.quick.isBlank()) {
            List<String> likes = new ArrayList<>();
            for (String field : query.quickFields) {
                if (meta.hasColumn(logical, field)) {
                    likes.add(alias + ".`" + field + "` LIKE ?");
                    args.add("%" + query.quick.replace("%", "\\%") + "%");
                }
            }
            if (!likes.isEmpty()) parts.add("(" + String.join(" OR ", likes) + ")");
        }
        if (query.initValue != null && !query.initValue.isBlank() && meta.hasColumn(logical, query.initKey)) {
            String op = "in".equalsIgnoreCase(query.initOperator) ? "IN" : "=";
            if ("IN".equals(op)) {
                String[] vals = query.initValue.split(",");
                StringBuilder marks = new StringBuilder();
                for (String v : vals) {
                    if (!marks.isEmpty()) marks.append(',');
                    marks.append('?');
                    args.add(v.trim());
                }
                parts.add(alias + ".`" + query.initKey + "` IN (" + marks + ")");
            } else {
                parts.add(alias + ".`" + query.initKey + "` = ?");
                args.add(query.initValue);
            }
        }
        for (Search s : query.searches) {
            if (!meta.hasColumn(logical, s.field)) continue;
            String op = canonical(s.operator);
            if (!OPS.contains(op)) continue;
            String col = alias + ".`" + s.field + "`";
            if ("datetime".equals(s.render) && "BETWEEN".equals(op)) {
                String[] pair = s.val.split(",", 2);
                if (pair.length < 2) continue;
                parts.add(col + " BETWEEN ? AND ?");
                args.add(parseTime(pair[0]));
                args.add(parseTime(pair[1]));
                continue;
            }
            if ("datetime".equals(s.render)) {
                parts.add(col + " = ?");
                args.add(parseTime(s.val));
                continue;
            }
            switch (op) {
                case "LIKE", "NOT LIKE" -> {
                    parts.add(col + " " + op + " ?");
                    args.add("%" + s.val.replace("%", "\\%") + "%");
                }
                case "IN", "NOT IN" -> {
                    String[] vals = s.val.split(",");
                    StringBuilder marks = new StringBuilder();
                    for (String v : vals) {
                        if (!marks.isEmpty()) marks.append(',');
                        marks.append('?');
                        args.add(v.trim());
                    }
                    parts.add(col + " " + op + " (" + marks + ")");
                }
                case "NULL" -> parts.add(col + " IS NULL");
                case "NOT NULL" -> parts.add(col + " IS NOT NULL");
                case "BETWEEN", "NOT BETWEEN" -> {
                    String[] pair = s.val.split(",", 2);
                    if (pair.length < 2) continue;
                    parts.add(col + " " + op + " ? AND ?");
                    args.add(pair[0]);
                    args.add(pair[1]);
                }
                case "FIND_IN_SET" -> {
                    parts.add("FIND_IN_SET(?, " + col + ")");
                    args.add(s.val);
                }
                default -> {
                    parts.add(col + " " + op + " ?");
                    args.add(s.val);
                }
            }
        }
        for (String extra : query.extraSql) {
            parts.add(extra);
        }
        args.addAll(query.extraArgs);
        if (parts.isEmpty()) return "";
        sb.append(" WHERE ").append(String.join(" AND ", parts));
        return sb.toString();
    }

    private String buildOrder(String logical, Query query, String pk) {
        String field = pk;
        String dir = "DESC";
        if (query.orderField != null && meta.hasColumn(logical, query.orderField)) {
            field = query.orderField;
            dir = "ASC".equalsIgnoreCase(query.orderDir) ? "ASC" : "DESC";
        }
        String sql = " ORDER BY m.`" + field + "` " + dir;
        if (!field.equals(pk)) {
            sql += ", m.`" + pk + "` DESC";
        }
        return sql;
    }

    private String canonical(String op) {
        if (op == null) return "=";
        return switch (op.toLowerCase(Locale.ROOT)) {
            case "eq" -> "=";
            case "ne" -> "<>";
            case "gt" -> ">";
            case "ge", "egt" -> ">=";
            case "lt" -> "<";
            case "le", "elt" -> "<=";
            case "like" -> "LIKE";
            case "not like" -> "NOT LIKE";
            case "in" -> "IN";
            case "not in" -> "NOT IN";
            case "range" -> "BETWEEN";
            case "not range" -> "NOT BETWEEN";
            case "null" -> "NULL";
            case "not null" -> "NOT NULL";
            case "find_in_set" -> "FIND_IN_SET";
            default -> op.toUpperCase(Locale.ROOT);
        };
    }

    private Object parseTime(String raw) {
        try {
            return java.time.LocalDateTime.parse(raw.trim().replace(" ", "T")).atZone(ZoneId.of("Asia/Shanghai")).toEpochSecond();
        } catch (Exception e) {
            try {
                return Long.parseLong(raw.trim());
            } catch (Exception ignored) {
                return 0;
            }
        }
    }

    public String remark(String path) {
        if (path == null || path.isBlank() || !meta.hasTable("admin_rule")) return "";
        try {
            List<String> rows = jdbc.query(
                    "SELECT remark FROM `" + meta.physical("admin_rule") + "` WHERE name = ? OR name = ? LIMIT 1",
                    (rs, i) -> rs.getString(1), path, path.contains("/") ? path : path);
            return rows.isEmpty() || rows.get(0) == null ? "" : rows.get(0);
        } catch (Exception e) {
            return "";
        }
    }

    public static final class Query {
        public int page = 1;
        public int limit = 10;
        public String quick;
        public List<String> quickFields = List.of("id");
        public String orderField;
        public String orderDir;
        public String initKey = "id";
        public String initValue;
        public String initOperator = "in";
        public String remarkPath = "";
        public List<Search> searches = new ArrayList<>();
        public List<String> extraSql = new ArrayList<>();
        public List<Object> extraArgs = new ArrayList<>();
    }

    public static final class Search {
        public String field;
        public String operator;
        public String val;
        public String render;
    }
}
