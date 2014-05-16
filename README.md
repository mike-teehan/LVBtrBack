# LVBtrBack Backup Server Setup

1. install ubuntu server 14.04

2. install a bunch of drives that will become the btrfs array

3. we're going to be naughty and use a root shell on ubuntu because it saves a lot of typing 'sudo'

    $ sudo su -

4. install software we're going to need

    \# aptitude install btrfs-tools lzop git php5-cli postfix

5. make some btrfs

    \# mkfs.btrfs -L backupdata -f -m raid1 -d raid1 /dev/sd[cdefghijklm] // adjust drive letters

6. set it up to automount

    \# echo "LABEL=backupdata /mnt/data btrfs defaults 0 0" >> /etc/fstab

7. check that btrfs is configured properly

    \# mount -a

    \# df // you should see your new btrfs mounted in here

8. generate a key for root

    \# ssh-keygen -f .ssh/id_rsa -t rsa -N ''

9. add entries to /etc/hosts for servers that will be backed up

    \# echo "${hostip} ${hostname}" >> /etc/hosts

    \# ssh-copy-id {$hostname}

10. make with the postfixing for the emails (append to the end)

    /etc/postfix/main.cf

    > relayhost = ${your.emailserver.com}:${port}

    > smtp\_sasl\_auth_enable = yes

    > smtp\_sasl\_password_maps = hash:/etc/postfix/sasl_passwd

    > smtp\_sasl\_security_options =

    \# echo “${your.emailserver.com}:${port} ${email_username@domain.com}:${secretpassword}” > /etc/postfix/sasl_passwd

    \# chmod 600 /etc/postfix/sasl_passwd 

    \# postmap /etc/postfix/sasl_passwd

11. git some LVBtrBack source

    \# git clone https://github.com/mike-teehan/LVBtrBack.git

12. add a line to the crontab

    \# crontab -e

    > MAILTO="your.email@here.com"

    > 5 0 * * 1-6 cd /root/LVBtrBack; php lv_backup.php --host=${hostname} --vg=${vg_to_backup} --lvs=${lvs_to_backup} --interval=daily;

