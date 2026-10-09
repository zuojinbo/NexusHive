package com.nexushive;

import com.nexushive.config.AppProperties;
import org.mybatis.spring.annotation.MapperScan;
import org.springframework.boot.SpringApplication;
import org.springframework.boot.autoconfigure.SpringBootApplication;
import org.springframework.boot.context.properties.EnableConfigurationProperties;
import org.springframework.scheduling.annotation.EnableScheduling;

@SpringBootApplication
@EnableScheduling
@EnableConfigurationProperties(AppProperties.class)
@MapperScan("com.nexushive.platform.mapper")
public class NexusHiveApplication {

    public static void main(String[] args) {
        SpringApplication.run(NexusHiveApplication.class, args);
    }
}
