INSERT IGNORE INTO settings(setting, name, value, hint) VALUES('timeoutpath', 'timeoutpath', '', 'This is used to limit the amount of time the above programs can run. You can the time limit in the process additional section. You can leave this empty to disable this. Use forward slashes in windows c:/path/to/timeout.exe');
INSERT IGNORE INTO settings(setting, name, value, hint) VALUES('timeoutseconds', 'timeoutseconds', '0', 'If you have set a path to the timeout binary, you can set the amount of seconds a program like unrar/ffmpeg can run here. 60 is a good value, you can leave it 0 to disable this.');