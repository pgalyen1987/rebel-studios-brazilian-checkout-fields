=== Campos Brasileiros para WooCommerce – CPF, CNPJ e CEP com validação ===
Contributors: rebelstudios
Tags: cpf, cnpj, cep, campos brasileiros, validar cpf
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Campos brasileiros no checkout do WooCommerce: validar CPF e CNPJ pelo dígito verificador, preencher o endereço pelo CEP. Dados no pedido e na API.

== Description ==

Este plugin adiciona ao checkout do WooCommerce os **campos brasileiros** que faltam: **CPF**, **CNPJ**, Razão Social, RG, Inscrição Estadual, número e bairro. E faz três coisas que normalmente faltam:

1. **Validação de verdade.** CPF e CNPJ são conferidos pelo **dígito verificador**, no navegador *e* no servidor. Um documento inválido nunca chega ao pedido.
2. **Preenchimento automático.** Digite o **CEP** e o endereço se preenche. Digite o **CNPJ** e a razão social e o endereço da empresa vêm junto.
3. **Os dados chegam onde você precisa.** CPF, CNPJ e razão social aparecem na tela do pedido, nos e-mails e na REST API — prontos para a sua integração de **nota fiscal (NF-e)**. Coletar um documento que você não consegue usar depois é justamente o problema que este plugin resolve.

= CEP automático: o endereço se preenche sozinho =

O cliente digita o CEP e quatro campos aparecem preenchidos — rua, bairro, cidade e estado. O número da casa ele ainda digita, porque consulta de CEP nenhuma sabe o número.

A consulta sai **do seu servidor**, nunca do navegador do cliente, e fica em cache por **30 dias**: o mesmo CEP não é consultado duas vezes. Se o serviço de consulta estiver fora do ar, o campo continua editável e o cliente digita o endereço como sempre fez — **a compra nunca é bloqueada** por uma API de terceiros.

O detalhe que costuma dar errado nos outros: aqui o CEP **manda** na cidade e no estado. O seletor de estado nunca chega vazio, porque o WooCommerce já deixa o estado da própria loja escolhido, e sem forçar isso um CEP do Rio continua dizendo São Paulo. Já a rua e o bairro, se o cliente tiver digitado algo antes, ficam como ele escreveu.

Este plugin **não calcula frete**. Ele preenche o endereço e valida os documentos, e usa nomes próprios nos campos (`billing_rsbcf_*`) em vez de disputar os genéricos, então convive com o seu plugin de frete em lugar de brigar com ele.

= Máscara de CPF, CNPJ e telefone enquanto se digita =

A **máscara de CPF** (`000.000.000-00`), a de **CNPJ** (`00.000.000/0000-00`), a de telefone (`(00) 00000-0000`) e a de data (`00/00/0000`) são aplicadas conforme o cliente digita. Não carregamos biblioteca de máscara no seu checkout: o valor é reescrito no próprio campo, e um documento colado já formatado continua valendo.

O limite, dito de uma vez: a máscara é conveniência do navegador. Sem JavaScript não há máscara — e mesmo assim um CPF inválido não passa, porque o dígito verificador é conferido **no servidor**.

Para **buscar CEP** quem responde é o ViaCEP. A BrasilAPI é consultada apenas para o CNPJ.

= Cada campo é opcional =

Cada campo tem três estados: **Desativado**, **Opcional** ou **Obrigatório**. Nenhuma loja virtual é igual à outra: uma loja de produtos digitais não precisa pedir CPF; quem fatura para empresas precisa de CNPJ e razão social e nada mais. Você decide, sem mexer em código.

= Funciona nos dois checkouts =

Compatível com o **checkout clássico** e o **checkout em blocos** do WooCommerce, e com **HPOS**. Os dados são gravados do mesmo jeito nos dois, então a sua integração fiscal lê um formato só.

= Privacidade =

Nenhum dado do seu cliente passa por um servidor nosso. As consultas de CEP e CNPJ saem do seu site direto para os dois serviços públicos listados em **Serviços externos**, e a resposta fica em cache no seu site. Nós não recebemos nada, nem para estatística.

E para o lado de dentro: os documentos que este plugin grava entram na exportação e na exclusão de dados pessoais do WordPress — com um porém, que está no FAQ, porque a opção que apaga os dados do **pedido** vem desligada no WooCommerce.

== In English ==

**Brazilian Checkout Fields** adds the fields a Brazilian store needs to the WooCommerce checkout — CPF, CNPJ, company legal name (Razão Social), RG, state registration (Inscrição Estadual), street number and neighbourhood (bairro) — and does three things that are usually missing:

1. **Real validation.** CPF and CNPJ are checked against the official check digits, in the browser *and* on the server. An invalid document never reaches the order.
2. **Autofill.** Type the CEP and the address fills itself in. Type the CNPJ and the company's legal name and address come with it.
3. **The data goes where you need it.** CPF, CNPJ and legal name appear on the order screen, in emails and in the REST API — ready for your invoicing (NF-e) integration. Collecting a document you cannot use later is the problem this plugin exists to solve.

The plugin's interface is in Brazilian Portuguese. It is fully internationalized (i18n), so it can be translated into any language through translate.wordpress.org once published.

= Automatic address fill from the CEP =

The customer types the postcode (CEP) and four fields fill themselves in — street, neighbourhood, city and state. They still type the house number, because no postcode lookup knows it.

The lookup is made **from your server**, never from the customer's browser, and is cached for **30 days**, so the same CEP is never looked up twice. If the lookup service is down the field stays editable and the customer types the address as they always did — **a purchase is never blocked** by a third-party API.

The part that usually goes wrong elsewhere: here the CEP **wins** on city and state. The state select is never empty — WooCommerce preselects the store's own state — so without forcing it, a CEP from Rio still says São Paulo. Street and neighbourhood, if the customer typed something first, are left as they wrote them.

This plugin **does not calculate shipping**. It fills the address and validates the documents, and it uses its own field names (`billing_rsbcf_*`) rather than the generic ones, so it lives alongside your shipping plugin instead of fighting it.

= Input masks for CPF, CNPJ and phone =

CPF (`000.000.000-00`), CNPJ (`00.000.000/0000-00`), phone (`(00) 00000-0000`) and date (`00/00/0000`) are masked as the customer types, by rewriting the field rather than loading a masking library, so a document pasted in already formatted still validates.

The limit, stated plainly: masking is a browser convenience. With JavaScript off there is no mask — and an invalid CPF still cannot get through, because the check digits are verified **on the server**.

= Every field is optional =

Each field has three states: **Disabled**, **Optional** or **Required**. A store that sells digital products does not need to ask for a CPF; one that invoices companies needs a CNPJ and legal name and nothing else. You decide, without touching code.

= Works with both checkouts =

Compatible with the WooCommerce **classic checkout** and the **block checkout**, and with **HPOS** (High-Performance Order Storage). Data is stored the same way by both checkouts, so an invoicing integration only has to read one format.

On the block checkout, customers choose between individual (Pessoa Física) and company (Pessoa Jurídica), and see only the documents for that choice.

= Privacy =

No customer data passes through a server of ours. CEP and CNPJ lookups go from your site straight to the two public services listed under **External services**, and the response is cached on your site. We receive nothing, not even analytics.

And on the inside: the documents this plugin stores are included in WordPress's personal-data export and erasure — with one catch, in the FAQ, because the option that erases **order** data ships switched off in WooCommerce.

= Inscrição Estadual and the accountant's CSV (paid add-on) =

This plugin **stores** the Inscrição Estadual; it does not check whether the number is well formed. [Checkout Brasil Pro](https://rebelstudiossoftware.com/checkout-brasil-pro.html) validates it against the published rule for each of the **27 states**, at checkout, and exports a CSV of orders with CPF, CNPJ, Razão Social, IE and the full address — with an **IE válida** column, so an invalid registration is caught before anyone tries to issue the NF-e rather than after the sale.

It **does not issue NF-e** — that needs a digital certificate and a SEFAZ integration, and is a different product. $29/year or $87 once for a lifetime licence, 7-day refund. The free plugin above does not expire and is not limited by it.

== External services ==

To fill an address from a postcode and a company from its registration number, this plugin sends the value the customer typed to two free, public Brazilian services. Every request is made **from your server** (never the customer's browser), **only** when a customer enters a CEP or a CNPJ, and the response is cached on your site so the same value is not looked up twice. No data is ever sent to the makers of this plugin.

**ViaCEP** (https://viacep.com.br/)
Used to turn a Brazilian postcode into a street address. When a customer types an 8-digit CEP, that CEP — and nothing else — is sent to ViaCEP, which returns the street, neighbourhood, city and state. No personal data (name, email, document number) is sent. ViaCEP is a free public service that does not publish a separate terms-of-service or privacy-policy page; its usage information is on its website: https://viacep.com.br/.

**BrasilAPI** (https://brasilapi.com.br/)
Used to turn a company registration number into the company's public details. When a customer types a 14-digit CNPJ (a public company registration number, not personal data), that CNPJ — and nothing else — is sent to BrasilAPI, which returns the company's public registration data (legal name and registered address). BrasilAPI is a free, open-source community project. Its terms of use are published on its site: https://brasilapi.com.br/#termos-de-uso . It does not publish a separate privacy-policy page; the project and its source are documented at https://brasilapi.com.br/ .

The Diagnostics panel on the settings screen also sends one sample request to each service (at most once every 5 minutes) to show whether it is responding. It uses a fixed example CEP and CNPJ, never customer data.

== Installation ==

1. Install and activate the plugin.
2. Go to **WooCommerce → Checkout Brasil**.
3. For each field, choose Disabled, Optional or Required.

== Frequently Asked Questions ==

= The fields do not appear on my checkout. =

It is almost always the checkout type. If your store uses the **block checkout** and your previous plugin only supported the classic checkout, no fields appear — which is exactly why this plugin exists.

Go to **WooCommerce → Checkout Brasil** and look at the **Diagnostics** panel at the bottom of the page. It tells you which checkout your store uses, whether the fields are active, and whether another plugin is competing for the same fields. Copy that report before opening a support request.

= Can I turn off the CPF? =

Yes. Every field has three states — Disabled, Optional, Required — under **WooCommerce → Checkout Brasil**. Nothing is forced, including the CPF.

= The fields appear twice. =

You have two checkout-field plugins active at the same time. The Diagnostics panel detects the common ones (Brazilian Market on WooCommerce, Checkout Field Editor, Flexible Checkout Fields). Deactivate one of them.

= I am migrating from "Brazilian Market on WooCommerce". Is my old data lost? =

Old orders stay intact — this plugin deletes nothing. It stores data in its own meta keys (`_billing_rsbcf_cpf`), different from the old plugin's (`_billing_cpf`), precisely to avoid conflicts. New orders use the new keys; old orders remain readable by the old plugin while it is installed.

= CEP lookup stopped working. Can my customers still buy? =

Yes. A failed lookup is ignored and the customer types the address normally. **A purchase is never blocked** by a third-party API. The Diagnostics panel shows whether ViaCEP and BrasilAPI are responding right now.

= Do CPF/CNPJ show up on the invoice? =

They are stored on the order (`_billing_rsbcf_cpf`, `_billing_rsbcf_cnpj`, `_billing_rsbcf_company_legal_name`) and shown on the order screen, in emails and in the REST API — which is where NF-e integrations read them. If your integration expects different field names, the `rsbcf_order_values` filter lets you remap them.

= Does it work with HPOS? =

Yes, compatibility is declared. Data is saved through the WooCommerce order API, so it works the same with HPOS on or off.

= Does it work with PagSeguro / Mercado Pago / PagBank? =

The fields use their own names (`billing_rsbcf_*`) instead of the generic names several plugins fight over, which is the most common cause of gateway conflicts. If you still hit a conflict, send the Diagnostics report and we can answer on the first reply.

= Dá para validar CPF e CNPJ de verdade, ou é só a máscara? =

Dá. O dígito verificador é conferido no navegador e **de novo no servidor**, então um documento inválido não entra no pedido nem com o JavaScript desligado. Sequências repetidas (111.111.111-11) são recusadas. A máscara é conveniência de quem digita; quem decide é o servidor.

In English: the official check digits are verified in the browser and **again on the server**, so an invalid document never reaches the order, with or without JavaScript. Repeated sequences (111.111.111-11) are rejected.

= Um cliente pediu para apagar os dados dele (LGPD). O plugin apaga o CPF? =

Apaga, pelas ferramentas do próprio WordPress. Em **Ferramentas → Apagar dados pessoais**, o CPF, o RG, a data de nascimento, o CNPJ, a Razão Social e a Inscrição Estadual saem do perfil do cliente — sempre — e saem também dos **pedidos** se a loja tiver marcado *Remove personal data from orders on request* em WooCommerce → Configurações → Contas e privacidade. Essa opção vem desmarcada: sem ela o WooCommerce preserva os dados do pedido de propósito, e o CPF ficaria lá depois de uma solicitação atendida. **Ferramentas → Exportar dados pessoais** inclui os mesmos campos. A ação em massa *Remover dados pessoais*, na tela de Pedidos, também limpa os campos deste plugin.

O limite: desinstalar o plugin não apaga nada. Ele não tem rotina de desinstalação, então a opção `rsbcf_fields` e os documentos gravados em `_billing_rsbcf_*` ficam no banco até você usar uma das ferramentas acima.

In English: WordPress's Export and Erase Personal Data tools cover every field this plugin stores. Erasure always clears the customer's profile; it clears the order only when *Remove personal data from orders on request* is ticked under WooCommerce → Settings → Accounts & Privacy, which is off by default. Deleting the plugin erases nothing — there is no uninstall routine.

= Do you receive my customers' data? =

No. Lookups go from your server straight to ViaCEP and BrasilAPI, and responses are cached on your site. There is no server of ours in between.

== Outros plugins da Rebel Studios ==

Plugins de WooCommerce que fazem uma coisa bem feita:

* **[Invoice Documents for WooCommerce](https://wordpress.org/plugins/rebel-studios-invoice-documents-woocommerce/):** notas e recibos em PDF, romaneios e notas de entrega, gerados automaticamente.
* **[Restaurant Menu & Reservations](https://wordpress.org/plugins/rebel-studios-restaurant-menu-reservations/):** menu por QR code e reservas de mesa.
* **[Event Tickets & QR Check-in](https://rebelstudiossoftware.com/wc-events-pro):** eventos, RSVP e ingressos com QR para conferir na entrada.
* **[Membership & Content Restriction](https://wordpress.org/plugins/rebel-studios-membership-content-restriction/):** restringe conteúdo a membros, com cadastro no front-end.

== Screenshots ==

1. Checkout fields with input masks, CPF validation and the address filled in from the CEP.
2. Settings: each field can be disabled, optional or required.
3. Diagnostics panel — versions, checkout type, HPOS, and whether the lookups are responding.

== Changelog ==

= 1.0.6 =
* Fix: on the classic checkout, letters typed just after changing the CEP could be lost, and the order stored "Cção" for "Consolação". WooCommerce takes the focus away from every field when the order review update comes back with a notice; on some stores with plain permalinks a leftover "added to your cart" notice comes back on every update. The field being typed in now keeps the focus. This also happens without this plugin, in WooCommerce's own fields, and they are protected too.
* Fix: with the individual/company choice on, a CNPJ set to required was labelled "(optional)" on the classic checkout, although a company could not check out without it. It now carries the required mark. On WooCommerce 8.9 to 9.8 the block checkout labels it "CNPJ (obrigatório para PJ)" (CPF: "obrigatório para PF").

= 1.0.4 =
* Fix: on the classic checkout, a returning customer who shipped to the billing address got the street number and neighbourhood of their previous shipping address on the order's shipping address, so the label pointed at the wrong house.
* Fix: on WooCommerce 8.9 to 9.8, the block checkout with the individual/company choice showed no CPF, CNPJ or legal name field at all, and with a document set to required it refused every buyer. Those versions now show both documents and check the required one, for the chosen type, when the order is placed. WooCommerce 9.9 and later are unchanged.
* Fix: on the classic checkout, a store that collects only company documents (CPF switched off, so no individual/company choice) did not enforce a required CNPJ and accepted a CNPJ with wrong check digits. Both are now refused, and the field is marked required.
* New: WordPress's "Export Personal Data" now includes the CPF, CNPJ, RG and the other fields this plugin stores, and "Erase Personal Data" removes them from the customer's profile and, when WooCommerce is set to remove order data on request, from their orders.
* Fix: searching WooCommerce > Orders for a CPF or CNPJ (digits only) finds the order on stores using High-Performance Order Storage, WooCommerce's default. It only worked on the older posts storage.
* Fix: on the classic checkout, a value posted for a field the store has switched off is no longer saved on the order.
* Fix: a Shop Manager can save the settings screen. WordPress refused the save to anyone without the administrator's capability.
* Fix: an empty required Número or Bairro shows one error on the classic checkout, not two.
* Fix: the Diagnostics panel now recognises Checkout Field Editor (it looked for the wrong file name) and this plugin's own earlier build, sold as "Checkout Brasil", which doubles every field when both are active.
* Change: a field being looked up (CEP or CNPJ) stays dimmed until the answer arrives, rather than for a fixed 0.9 seconds. The spinner it was meant to show could never appear on an input.

= 1.0.3 =
* Renamed to "Rebel Studios Brazilian Checkout Fields for WooCommerce" and moved to a unique, plugin-specific code prefix ahead of the WordPress.org listing. No change to features.
* Documented the external services (ViaCEP, BrasilAPI) fully in the readme.

= 1.0.2 =
* Fix: CEP autofill now sets the state. It filled street, neighbourhood and city but left the state WooCommerce preselects (the store's own), so a Rio CEP on a São Paulo store kept "São Paulo".
* Fix: CNPJ autofill fills the registered address only as a whole, and only while the customer hasn't started typing one, so it can no longer mix the company's street into the customer's CEP and state.

= 1.0.1 =
* Fix: on the block checkout, a CNPJ typed digit by digit was scrambled by the input mask.
* Fix: CEP and CNPJ autofill now works on the block checkout (it only worked on the classic checkout).
* New on the block checkout: choose individual or company, with CPF, or CNPJ and legal name, to match.
* New on the block checkout: street number and neighbourhood on the shipping address too, not only billing.
* Fix: a CPF typed and then hidden by switching to company is no longer saved on the order (both checkouts).
* Fix: documents were shown twice on the confirmation page and in emails for block checkout orders.

= 1.0.0 =
* Initial release: CPF, CNPJ, legal name, RG, state registration, street number, neighbourhood and mobile phone.
* Check-digit validation in the browser and on the server.
* Address lookup by CEP (ViaCEP) and company lookup by CNPJ (BrasilAPI), server-side and cached.
* Supports the classic checkout, the block checkout and HPOS.
* Data on the order, the order screen, emails and the REST API.

== Upgrade Notice ==

= 1.0.6 =
Stops the classic checkout from losing letters typed right after the CEP on some plain-permalink stores, and labels a CNPJ required for companies as required.

= 1.0.4 =
Fixes a wrong house number on some shipping addresses (classic checkout), missing document fields on the block checkout of WooCommerce 8.9 to 9.8, and an unenforced required CNPJ; adds personal-data export and erasure.

= 1.0.3 =
Renamed plugin and internal code prefix ahead of the WordPress.org listing; no change to features.

= 1.0.1 =
Fixes CNPJ input and autofill on the block checkout, and adds individual/company choice and shipping number/neighbourhood there.

= 1.0.0 =
Initial release.
