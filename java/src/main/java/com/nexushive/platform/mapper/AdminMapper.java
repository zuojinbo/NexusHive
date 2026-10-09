package com.nexushive.platform.mapper;

import com.baomidou.mybatisplus.core.mapper.BaseMapper;
import com.nexushive.platform.entity.AdminAccount;
import org.apache.ibatis.annotations.Mapper;

@Mapper
public interface AdminMapper extends BaseMapper<AdminAccount> {
}
