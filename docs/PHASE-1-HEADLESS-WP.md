# Phase 1 — 헤드리스 WordPress (`cms.irisid.com`)

> **목표:** InMotion VPS에 헤드리스 WP 백엔드 구축 — CPT/ACF/WPGraphQL/Gravity Forms, 프론트 차단, ISR webhook 준비.  
> **선행 문서:** [CONTENT-MODEL.md](CONTENT-MODEL.md)

---

## 체크리스트

### A. cPanel / DNS (1회)

- [ ] **서브도메인** `cms.irisid.com` 생성 (또는 스테이징 경로 — 프로덕션 irisid.com과 **별도 docroot**)
- [ ] **SSL** (AutoSSL 또는 Cloudflare Full)
- [ ] **PHP 8.2+**, memory_limit ≥ 256M, upload_max_filesize ≥ 64M
- [ ] **MySQL** DB + 사용자 생성
- [ ] Cloudflare: `cms` 서브도메인 **DNS only** 또는 Full — GraphQL POST 허용 확인

### B. WordPress 설치

- [ ] 최신 WordPress 설치 (기본 테마 Twenty Twenty-Four 등 — **프론트는 403**, 테마는 상관없음)
- [ ] **Settings → General:** Site URL = `https://cms.irisid.com`
- [ ] **Settings → Permalinks:** Post name (`/%postname%/`) → Save
- [ ] **Settings → Reading:** Search engine visibility **discouraged** (mu-plugin도 noindex 처리)

### C. 플러그인 설치 (순서)

| # | 플러그인 | 용도 |
|---|----------|------|
| 1 | **Advanced Custom Fields PRO** | 필드 + GraphQL 노출 |
| 2 | **WPGraphQL** | GraphQL API |
| 3 | **WPGraphQL for ACF** | ACF → GraphQL |
| 4 | **Yoast SEO** | 메타/사이트맵 (프론트용 데이터) |
| 5 | **Add WPGraphQL SEO** (또는 WPGraphQL Yoast SEO) | Yoast → GraphQL |
| 6 | **Gravity Forms** | 문의/등록/RMA/게이트 다운로드 |
| 7 | **WPGraphQL for Gravity Forms** | 폼 → GraphQL mutation |
| 8 | **WP Mail SMTP** | Resend SMTP |
| 9 | **Redirection** | cms 내부 리다이렉트(선택) — **공개 301은 Next.js** |
| 10 | **WPGraphQL Smart Cache** (선택) | GraphQL 캐시 |

설치 후 **GraphiQL IDE** 활성화 (WPGraphQL 설정).

### D. mu-plugin 배포 (이 저장소)

```bash
chmod +x scripts/deploy-wordpress-mu-plugin.sh

export WP_SSH="irisid5@173.231.221.180"   # cPanel 사용자명 확인
export WP_ROOT="/home/irisid5/public_html/cms"  # 실제 cms docroot

./scripts/deploy-wordpress-mu-plugin.sh
```

배포물:
- CPT: `product`, `solution`, `resource`, `download`, `faq`
- Taxonomies: `product_type`, `resource_type`, `download_type`, `faq_category`, `solution_tax`
- `resource_type` / `product_type` **초기 term 자동 시드**
- 헤드리스 **프론트 403** (GraphQL/REST/admin만 허용)
- **ISR revalidate webhook** (저장 시 Next 호출)
- ACF JSON 8개 필드그룹

배포 후:
1. WP Admin → **Settings → Permalinks → Save** (rewrite flush)
2. **Custom Fields** → 필드그룹 **Sync available** 있으면 Sync
3. GraphiQL에서 `{ products { nodes { title } } }` 테스트

### E. wp-config.php 추가

`wp-config.php` (`/* That's all, stop editing! */` 위):

```php
// Headless — ISR revalidate (Phase 2 Next 배포 후 URL 갱신)
define('IRISID_REVALIDATE_URL', 'https://irisid.com/api/revalidate');
define('IRISID_REVALIDATE_SECRET', 'CHANGE_ME_long_random_string');

// GraphQL CORS — Next 프론트 origin만 (Phase 2)
// define('GRAPHQL_CORS_ALLOWED_ORIGINS', 'https://irisid.com,https://staging.irisid.com');
```

`.env` / secrets는 **커밋하지 않음**.

### F. ACF 필드그룹 (자동 sync)

| 파일 | 대상 |
|------|------|
| `group_irisid_product.json` | product |
| `group_irisid_solution.json` | solution |
| `group_irisid_resource.json` | resource (396 posts) |
| `group_irisid_download.json` | download |
| `group_irisid_faq.json` | faq |
| `group_irisid_page_sections.json` | page (유연 섹션) |
| `group_irisid_discontinued.json` | page slug `discontinued` |
| `group_irisid_site_options.json` | Site Settings options |

ACF → **Show in GraphQL** 전부 ON (JSON에 포함됨).

### G. Site Settings

WP Admin → **Site Settings**:
- Knowledge Base URL → **Salesforce** 외부 URL
- Header logo, contact phone/email

### H. 초기 콘텐츠 시드 (slug = live URL)

**Product** (18) — slug는 [CONTENT-MODEL §3-1](CONTENT-MODEL.md) 참조, `product_type` term 할당:
- HW: `irisaccess-ia1000`, `irisaccess-it100`, `ibar-600e`, `icam-d2000`, `icam-td200`, `icam-t10`, `icam-t20`, `icam-m300`, `icam-r100-series`, `icam-r200`, `ou500`
- SW: `iams`, `itms`, `itms-cloud`, `idata-eac`, `idata-iris-sdk`, `irisaccelerator`, `irisaccelerator-m`

**Solution** (9) — slug: `access-control`, `time-attendance`, … ([§3-2](CONTENT-MODEL.md))

**Page** — slug: `home`(front), `products`, `solutions`, `resources`, `support`, `company`, `discontinued`, `faq`, `privacy`, `legal`, `company/about-iris-id` 등은 **nested slug** 또는 parent page 구조로 재현 (WP는 `/company/about-iris-id/` = page slug `about-iris-id` + parent `company`)

**Discontinued hub** — page slug `discontinued`, repeater에 단종 모델 입력 (개별 CPT 없음)

**Resource** — 396 posts: 마이그레이션 스크립트는 Phase 1b (별도)

### I. Gravity Forms

기존 live에서 폼 **export JSON** → cms에 import:
- Contact Us
- Product Registration
- RMA Request
- Software Upgrade Request
- License Request
- 게이트 다운로드/웨비나 폼들

GraphiQL 테스트:
```graphql
mutation {
  submitGravityFormsForm(input: { id: 1, fieldValues: [] }) {
    entry { id }
  }
}
```

### J. Resend (WP Mail SMTP)

- Mailer: Other SMTP 또는 Resend API plugin
- From: `noreply@irisid.com` (SPF/DKIM Cloudflare/DNS 확인)

### K. 검증

| 테스트 | 기대 |
|--------|------|
| `https://cms.irisid.com/` | 403 plain text |
| `https://cms.irisid.com/graphql` | GraphQL endpoint |
| `https://cms.irisid.com/wp-admin/` | 로그인 |
| GraphiQL `{ siteSettings { siteSettingsFields { knowledgeBaseUrl } } }` | Salesforce URL |
| GraphiQL `{ products { nodes { slug productFields { subtitle } } } }` | ACF 노출 |
| 콘텐츠 저장 | revalidate webhook 로그 (Phase 2 Next 연결 후) |

---

## GraphQL 샘플 쿼리

```graphql
query ProductBySlug($slug: ID!) {
  product(id: $slug, idType: SLUG) {
    title
    slug
    productTypes { nodes { slug name } }
    productFields {
      subtitle
      shortDescription
      heroImage { sourceUrl altText }
      keyFeatures { title text }
    }
    seo {
      title
      metaDesc
      opengraphImage { sourceUrl }
    }
  }
}
```

```graphql
query Menu {
  menu(id: "primary", idType: NAME) {
    menuItems {
      nodes {
        label
        url
        childItems { nodes { label url } }
      }
    }
  }
}
```

메뉴 `primary`는 WP **Appearance → Menus**에서 live 구조대로 구성.

---

## 301 리다이렉트 (Next.js — Phase 2)

cms가 아닌 **Next `next.config.js`** 또는 Cloudflare Rules:

원본 목록: [`migration/redirects-301.csv`](migration/redirects-301.csv)

---

## Phase 1b — 콘텐츠 마이그레이션 (다음)

1. Live WP export (WXR) 또는 scrape → `resource` 396 + pages
2. 미디어 `wp-content/uploads` rsync → cms
3. slug 1:1 매핑 검증 (`docs/migration/urls-*.txt` 대조)

---

## 저장소 파일 맵

| 경로 | 내용 |
|------|------|
| `wordpress/mu-plugins/` | 헤드리스 mu-plugin |
| `docs/CONTENT-MODEL.md` | IA + ACF 스키마 |
| `docs/migration/urls-*.txt` | Live URL 인벤토리 |
| `docs/migration/redirects-301.csv` | 301 목록 |
| `docs/PHASE-1B-MIGRATION.md` | Phase 1b 콘텐츠 마이그레이션 단계 |
| `scripts/deploy-wordpress-mu-plugin.sh` | VPS 배포 |
| `scripts/migration/sync-uploads.sh` | live → cms uploads rsync |

---

## Phase 1b (다음)

Phase 1 인프라 완료 후 → **[PHASE-1B-MIGRATION.md](PHASE-1B-MIGRATION.md)**  
순서: 백업 → uploads → 제품 18 (파일럿 `icam-d2000`) → 솔루션 → 페이지 → resource 396.

---

## 트러블슈팅

| 증상 | 조치 |
|------|------|
| GraphQL 404 | Permalinks Save; WPGraphQL 활성 확인 |
| ACF 필드 GraphQL에 없음 | WPGraphQL for ACF 활성; 필드 Sync; Show in GraphQL |
| mu-plugin 500 | PHP 8.2 `str_starts_with` — PHP 버전 확인 |
| CORS error (Phase 2) | WPGraphQL CORS 또는 Next server-side fetch |
| revalidate 실패 | `IRISID_REVALIDATE_*` 정의; Next `/api/revalidate` 배포 여부 |
