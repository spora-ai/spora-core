{
    "apps": [
        {
            "name": "spora",
            "script": "bin/spora",
            "args": "serve",
            "interpreter": "php",
            "instances": 1,
            "autorestart": true,
            "watch": false,
            "max_memory_restart": "256M",
            "env": {
                "SPORA_DB_TYPE": "sqlite",
                "SPORA_DB_PATH": "storage/spora.db",
                "SPORA_SYNC_MODE": "false"
            }
        }
    ]
}