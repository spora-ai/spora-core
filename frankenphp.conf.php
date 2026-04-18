{
    admin {
        listen 127.0.0.1:2019
    }
    servers {
        demo {
            listen tls:8443
            protocols h1 h2 h3
        }
        main {
            listen any:80
            listen any:443 tls
        }
    }
    logging {
        level info
    }
    environment {
        FRANKENPHP_CONFIG "worker bin/spora worker:run"
    }
}