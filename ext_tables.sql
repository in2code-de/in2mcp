CREATE TABLE tx_in2mcp_oauth_client (
    uid int(11) unsigned NOT NULL auto_increment,
    client_id varchar(64) DEFAULT '' NOT NULL,
    client_name varchar(255) DEFAULT '' NOT NULL,
    redirect_uris text,
    registration_type varchar(16) DEFAULT '' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY client_id (client_id)
);

CREATE TABLE tx_in2mcp_oauth_grant (
    uid int(11) unsigned NOT NULL auto_increment,
    be_user int(11) unsigned DEFAULT '0' NOT NULL,
    client_id varchar(64) DEFAULT '' NOT NULL,
    scope varchar(255) DEFAULT '' NOT NULL,
    resource varchar(255) DEFAULT '' NOT NULL,
    access_token_hash char(64) DEFAULT '' NOT NULL,
    access_token_expires int(11) unsigned DEFAULT '0' NOT NULL,
    refresh_token_hash char(64) DEFAULT '' NOT NULL,
    previous_refresh_token_hash char(64) DEFAULT '' NOT NULL,
    refresh_token_expires int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY access_token (access_token_hash),
    KEY refresh_token (refresh_token_hash),
    KEY previous_refresh_token (previous_refresh_token_hash),
    KEY be_user (be_user)
);
