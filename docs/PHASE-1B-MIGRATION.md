# Phase 1b — 콘텐츠 마이그레이션 (live → cms)

> **전제:** live `irisid.com` Kubio WP는 **건드리지 않음**. cms `cms.irisid.com`에만 입력.  
> **slug 1:1 유지** — [`migration/urls-pages.txt`](migration/urls-pages.txt), [`urls-posts.txt`](migration/urls-posts.txt)  
> **자동 싱크 없음** — 1b는 1차 이전, 컷오버 직전 **델타 1회** 추가.

---

## 마이그레이션 맵 (live → cms CPT)

| live (Kubio) | cms (헤드리스) | 개수 | slug |
|--------------|----------------|------|------|
| `page` `/products/{type}/{slug}/` | `product` + `product_type` | 18 | leaf slug 동일 |
| `page` `/solutions/{slug}/` | `solution` | 10 | 동일 |
| `post` (루트 URL) | `resource` + `resource_type` | 396 | 동일 |
| `page` 허브·회사·지원·캠페인 | `page` + ACF `sections` | ~70 | 동일 |
| `download` (신규) | `download` CPT | TBD | Support/리터러처에서 추출 |
| FAQ | `faq` | TBD | `/faq/` |
| 단종 | `page` `/discontinued/` repeater | — | 개별 URL → 301 (§9.4) |

Kubio HTML → ACF 필드 **수동/반자동** (복사·재배치). 자동 변환 없음.

---

## 단계별 진행 (차근차근)

### Step 0 — 준비 (오늘)

- [ ] live **전체 백업** (InMotion/cPanel) — 롤백용
- [ ] cms **전체 백업** (마이그레이션 전후 스냅샷)
- [ ] live WP Admin 로그인 URL·계정 확인
- [ ] 이 체크리스트 북마크

**완료 기준:** 백업 2개 확보.

---

### Step 1 — 미디어 (uploads)

live와 cms **같은 VPS**이면 rsync가 가장 빠름 (경로는 cPanel에서 확인).

```bash
# 예시 — live / cms docroot 확인 후 수정
LIVE_UPLOADS=/home/irisid5/public_html/wp-content/uploads
CMS_UPLOADS=/home/irisid5/cms.irisid.com/wp-content/uploads

rsync -av --ignore-existing "$LIVE_UPLOADS/" "$CMS_UPLOADS/"
```

cPanel만 쓸 경우: File Manager → live `uploads` → Compress → cms `uploads`에 Upload → Extract.

- [ ] cms **Settings → Media** 에서 이미지 URL 깨짐 없는지 샘플 5장 확인

**완료 기준:** cms에서 live 업로드 경로(`.../uploads/2024/...`) 이미지가 Media Library 또는 본문에서 로드됨.

---

### Step 2 — 제품 18개 (파일럿, 최우선)

목록: [`migration/batch-products.txt`](migration/batch-products.txt)

**각 제품 작업 (1개당 ~30–60분):**

1. cms **Products → Add New**
2. **Title** = live와 동일
3. **Slug** = live leaf slug (예: `icam-d2000`) — **수동 확인**
4. **Product Type** = Hardware / Software
5. ACF: hero, short_description, key_features, specifications, gallery, spec_sheet PDF
6. live 페이지 열어 **텍스트·이미지·PDF** 복사 (Kubio → ACF)
7. GraphiQL:

```graphql
{
  product(id: "icam-d2000", idType: SLUG) {
    title slug
    productTypes { nodes { slug } }
    productFields { shortDescription }
  }
}
```

**권장 순서:** `icam-d2000` 1개 완전히 끝 → 나머지 17개 템플릿 복제.

- [ ] HW 11 + SW 7 = 18 Published

**완료 기준:** 18 slug GraphQL 조회 OK.

---

### Step 3 — 솔루션 10개

목록: [`migration/batch-solutions.txt`](migration/batch-solutions.txt)

Step 2와 동일 (CPT `solution`, ACF solutionFields).

- [ ] 10 Published + GraphiQL `solutions`

---

### Step 4 — 허브·회사·지원 페이지 (`page` + sections)

목록: [`migration/batch-pages-hubs.txt`](migration/batch-pages-hubs.txt)

1. **Slug** live와 동일 (parent page: `company` → slug `about-iris-id` 등)
2. ACF **Page Sections** — `card_grid`, `hero`, `form_block` (GF id는 cms import ID 사용)
3. Contact Us → `form_block` **gf_form_id: 1**

**GF Form ID (cms, import 후):**

| Form | databaseId |
|------|------------|
| Contact Us | 1 |
| Product Registration | 2 |
| RMA Request | 3 |
| Software Upgrade Request | 4 |

- [ ] 허브 5 + company 6 + support 4 + legal/faq

---

### Step 5 — 리소스 396개 (`post` → `resource`)

목록: [`migration/urls-posts.txt`](migration/urls-posts.txt)

**방법 A — WXR (권장 1차):**

1. live **Tools → Export → Posts** (또는 All content)
2. cms **Tools → Import → WordPress** (Importer 플러그인 설치)
3. Import as **posts** (일단)
4. 일괄 변환:
   - Plugin **Post Type Switcher** 또는
   - WP-CLI: `wp post list --post_type=post --format=ids | xargs -I % wp post update % --post_type=resource`
5. **resource_type** taxonomy:
   - 기본 `news-media` (대부분 보도/뉴스)
   - live **Category** 매핑표 만들어 수동/스크립트 보정 (press-release, insights, case-studies 등)

**방법 B — 배치 (50개씩):** 오래된 글부터 주 단위.

- [ ] 396 `resource` Published
- [ ] slug GraphQL spot-check 20개

**완료 기준:** `resources { nodes { slug } }` count ≥ 396.

---

### Step 6 — 다운로드 & 캠페인 랜딩

목록: [`migration/batch-campaigns.txt`](migration/batch-campaigns.txt)

- **`download` CPT:** Support Software/Drivers, Literature PDF, gated whitepaper
- **캠페인 page:** `form_block` + `download` gated 연결
- GF id 5–9 (whitepaper 등) 매핑

---

### Step 7 — 단종 허브 + 301 목록

- cms **Pages → discontinued** — ACF `discontinued_items` repeater 입력
- 301: [`migration/redirects-301.csv`](migration/redirects-301.csv) (Next Phase 2에서 구현)

---

### Step 8 — QA

- [ ] 519 URL vs cms slug 스프레드시트 대조
- [ ] GraphiQL 샘플: product, solution, resource, page, gfForm
- [ ] monitor repo `BASE_URL=https://staging...` (Phase 2 후) 또는 수동 체크리스트
- [ ] 이미지·PDF 링크 spot-check 30 URL

---

## 주간 권장 페이스

| 주 | Step | 목표 |
|----|------|------|
| 1 | 0–1–2 | 백업, 미디어, 제품 18 |
| 2 | 3–4 | 솔루션 10, 허브·회사·지원 핵심 페이지 |
| 3–4 | 5 | resource 396 (배치) |
| 5 | 6–8 | 다운로드, 캠페인, QA |

---

## 지금 당장 (Step 0 → 1)

1. live + cms **백업**
2. **uploads rsync** (Step 1)
3. **제품 1개** `icam-d2000` 파일럿 (Step 2)

Step 1 uploads 경로 확인이 필요하면 cPanel File Manager에서 live/cms `wp-content/uploads` 스크린샷 보내주세요.

---

## 관련 파일

| 파일 | 용도 |
|------|------|
| `migration/batch-products.txt` | 제품 URL 18 |
| `migration/batch-solutions.txt` | 솔루션 URL |
| `migration/batch-pages-hubs.txt` | 허브·회사·지원·법무 |
| `migration/batch-campaigns.txt` | 캠페인·리드젠 |
| `migration/urls-posts.txt` | resource 396 |
| `migration/redirects-301.csv` | 컷오버 301 |
