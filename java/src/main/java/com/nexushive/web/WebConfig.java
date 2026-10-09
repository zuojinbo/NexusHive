package com.nexushive.web;

import com.fasterxml.jackson.databind.ObjectMapper;
import com.nexushive.common.ApiResult;
import com.nexushive.config.AppProperties;
import jakarta.servlet.FilterChain;
import jakarta.servlet.ServletException;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletResponse;
import org.springframework.boot.web.servlet.FilterRegistrationBean;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;
import org.springframework.core.Ordered;
import org.springframework.http.MediaType;
import org.springframework.web.filter.OncePerRequestFilter;
import org.springframework.web.servlet.config.annotation.ResourceHandlerRegistry;
import org.springframework.web.servlet.config.annotation.WebMvcConfigurer;

import java.io.IOException;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.Map;
import java.util.concurrent.ConcurrentHashMap;

@Configuration
public class WebConfig implements WebMvcConfigurer {
    private final AppProperties props;
    private final ObjectMapper mapper;

    public WebConfig(AppProperties props, ObjectMapper mapper) {
        this.props = props;
        this.mapper = mapper;
    }

    @Override
    public void addResourceHandlers(ResourceHandlerRegistry registry) {
        String dir = Path.of(props.getPublicDir()).toAbsolutePath().normalize().toString();
        if (Files.isDirectory(Path.of(dir))) {
            registry.addResourceHandler("/**").addResourceLocations("file:" + dir + "/");
        }
    }

    @Bean
    public FilterRegistrationBean<OncePerRequestFilter> legacyPathFilter() {
        FilterRegistrationBean<OncePerRequestFilter> bean = new FilterRegistrationBean<>();
        bean.setOrder(Ordered.HIGHEST_PRECEDENCE);
        bean.setFilter(new OncePerRequestFilter() {
            @Override
            protected void doFilterInternal(HttpServletRequest request, HttpServletResponse response, FilterChain chain)
                    throws ServletException, IOException {
                if (request.getAttribute("nexushive.forwarded") != null) {
                    chain.doFilter(request, response);
                    return;
                }
                String uri = request.getRequestURI();
                String s = request.getParameter("s");
                if (s != null && !s.isBlank()) {
                    request.setAttribute("nexushive.forwarded", true);
                    String target = s.startsWith("/") ? s : "/" + s;
                    request.getRequestDispatcher(target).forward(request, response);
                    return;
                }
                if (uri.startsWith("/index.php/")) {
                    request.setAttribute("nexushive.forwarded", true);
                    request.getRequestDispatcher(uri.substring("/index.php".length())).forward(request, response);
                    return;
                }
                chain.doFilter(request, response);
            }
        });
        return bean;
    }

    @Bean
    public FilterRegistrationBean<OncePerRequestFilter> corsFilter() {
        FilterRegistrationBean<OncePerRequestFilter> bean = new FilterRegistrationBean<>();
        bean.setOrder(Ordered.HIGHEST_PRECEDENCE + 1);
        bean.setFilter(new OncePerRequestFilter() {
            @Override
            protected void doFilterInternal(HttpServletRequest request, HttpServletResponse response, FilterChain chain)
                    throws ServletException, IOException {
                String origin = request.getHeader("Origin");
                if (origin != null && !origin.isBlank()) {
                    response.setHeader("Access-Control-Allow-Origin", origin);
                }
                response.setHeader("Access-Control-Allow-Credentials", "true");
                response.setHeader("Access-Control-Allow-Methods", "*");
                response.setHeader("Access-Control-Allow-Headers", "*");
                response.setHeader("Access-Control-Max-Age", "1800");
                if ("OPTIONS".equalsIgnoreCase(request.getMethod())) {
                    response.setStatus(204);
                    return;
                }
                chain.doFilter(request, response);
            }
        });
        return bean;
    }

    @Bean
    public FilterRegistrationBean<OncePerRequestFilter> throttleFilter() {
        FilterRegistrationBean<OncePerRequestFilter> bean = new FilterRegistrationBean<>();
        bean.setOrder(Ordered.HIGHEST_PRECEDENCE + 2);
        bean.setFilter(new OncePerRequestFilter() {
            private final Map<String, long[]> windows = new ConcurrentHashMap<>();

            @Override
            protected void doFilterInternal(HttpServletRequest request, HttpServletResponse response, FilterChain chain)
                    throws ServletException, IOException {
                String method = request.getMethod();
                if (!"GET".equalsIgnoreCase(method) && !"HEAD".equalsIgnoreCase(method)) {
                    chain.doFilter(request, response);
                    return;
                }
                String ip = request.getRemoteAddr();
                long now = System.currentTimeMillis();
                long[] slot = windows.compute(ip, (k, v) -> {
                    if (v == null || now - v[0] > 60_000) return new long[]{now, 1};
                    v[1]++;
                    return v;
                });
                if (slot[1] > 120) {
                    response.setStatus(200);
                    response.setContentType(MediaType.APPLICATION_JSON_VALUE);
                    response.setCharacterEncoding("UTF-8");
                    mapper.writeValue(response.getWriter(), ApiResult.fail("Please do not request frequently. Try again in 60 seconds.").toMap());
                    return;
                }
                chain.doFilter(request, response);
            }
        });
        return bean;
    }
}
