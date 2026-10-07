INTEGRAÇÃO DE STATUS COM A CENTRAL

Com as APIs atualmente fornecidas, NÃO existe endpoint documentado que permita
alterar diretamente os estados automáticos:
- scheduled
- attended
- no_show
- enrolled

A API de Contatos só permite PATCH manual para:
new, selected, not_selected, invited, lost.

A API Intake permite criar/completar lead, consultar status/agendamento,
agendar e reagendar. Ela aceita endereço em PATCH /leads/{contact}, porém
GET /leads/{contact} não devolve o endereço residencial do contato; apenas
school_address do agendamento.

Por isso esta versão não inventa uma rota para attended/no_show/enrolled.
Quando a API de Visitas/Agendamentos da Central for disponibilizada,
o vínculo centralContactId já gravado nas visitas permite conectar esse fluxo.
