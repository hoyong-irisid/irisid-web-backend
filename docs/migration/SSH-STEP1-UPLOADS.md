# Step 1 — SSH 설정 + uploads 동기화

> VPS: `irisid5@173.231.221.180` (InMotion)  
> live uploads → cms uploads (같은 서버 내부 rsync)

---

## A. SSH 켜기 (InMotion cPanel)

VPS는 보통 SSH가 이미 열려 있습니다. **Mac에서 접속이 안 될 때**만 아래 확인.

### 1) cPanel에서 SSH / Terminal

1. InMotion 로그인 → **Manage** → **cPanel**
2. **Security → SSH Access** (또는 **Terminal**)
3. **Manage SSH Keys** → Mac 공개키 등록 (아래 B 참고)
4. (있으면) **Enable SSH access** / jailed shell 확인

### 2) InMotion AMP (안 될 때)

1. [amp.inmotionhosting.com](https://amp.inmotionhosting.com) → 해당 VPS
2. Support / SSH 관련: **SSH enabled**, 방화벽 **port 22** 허용 확인

### 3) 접속 정보

| 항목 | 값 |
|------|-----|
| Host | `173.231.221.180` |
| User | `irisid5` (cPanel username) |
| Port | `22` (다르면 cPanel SSH Access에 표시) |
| Auth | **SSH key 권장** (비밀번호는 deploy 때 실패한 적 있음) |

---

## B. Mac — SSH 키 등록 (1회)

터미널(Mac):

```bash
# 키 없으면 생성 (Enter 연타, passphrase 선택)
ls ~/.ssh/id_ed25519.pub || ssh-keygen -t ed25519 -C "irisid-migration"

# 공개키 출력 → cPanel SSH Access → Import Key → 붙여넣기 → Authorize
cat ~/.ssh/id_ed25519.pub
```

cPanel **SSH Access → Manage SSH Keys → Import Key**:

- Key Name: `mac-irisid`
- Public Key: 위 `cat` 출력 전체 한 줄
- **Authorize** 클릭

접속 테스트:

```bash
ssh irisid5@173.231.221.180
```

프롬프트가 `irisid5@...` 로 바뀌면 성공. `exit` 로 빠져나옴.

---

## C. uploads 경로 확인 (SSH 접속 후)

```bash
ssh irisid5@173.231.221.180

# live
ls -la /home/irisid5/public_html/wp-content/uploads | head

# cms (서브도메인 docroot — cPanel Subdomains에서 확인)
ls -la /home/irisid5/cms.irisid.com/wp-content/uploads 2>/dev/null || \
ls -la /home/irisid5/public_html/cms.irisid.com/wp-content/uploads 2>/dev/null
```

경로가 다르면 `LIVE_UPLOADS` / `CMS_UPLOADS` 환경변수로 지정 (아래 D).

---

## D. rsync 실행 (Mac에서, repo 루트)

```bash
cd /Users/hoyonglee/Documents/irisid-web-backend

export WP_SSH=irisid5@173.231.221.180

# (선택) 먼저 dry-run — 복사 없이 목록만
DRY_RUN=1 ./scripts/migration/sync-uploads-via-ssh.sh

# 실제 복사
./scripts/migration/sync-uploads-via-ssh.sh
```

경로가 다를 때:

```bash
export WP_SSH=irisid5@173.231.221.180
export LIVE_UPLOADS=/home/irisid5/public_html/wp-content/uploads
export CMS_UPLOADS=/home/irisid5/실제/cms/경로/wp-content/uploads
./scripts/migration/sync-uploads-via-ssh.sh
```

`--ignore-existing`: cms에 이미 있는 파일은 건너뜀. **재실행 안전.**

---

## E. 검증

1. cms **WP Admin → Media → Library** — 최근 연도 폴더 파일 보이는지
2. 또는 GraphQL/본문에서 `https://cms.irisid.com/wp-content/uploads/2024/...` URL 직접 열기

---

## 트러블슈팅

| 증상 | 조치 |
|------|------|
| `Permission denied (publickey,password)` | cPanel SSH key **Authorize**; 또는 `ssh -v irisid5@...` 로 디버그 |
| `LIVE_UPLOADS not found` | C에서 docroot 경로 확인 후 `export LIVE_UPLOADS=...` |
| `rsync: command not found` | 서버에 rsync 없음 → cPanel 터미널에서 `which rsync`; 없으면 `yum install rsync` (root/WHM) |
| 용량/시간 오래 걸림 | uploads 수 GB — 백그라운드: SSH 접속 후 서버에서 직접 `nohup rsync ... &` |

---

## Step 1 완료 후

→ [pilot-product-icam-d2000.md](pilot-product-icam-d2000.md) (Step 2 제품 파일럿)
