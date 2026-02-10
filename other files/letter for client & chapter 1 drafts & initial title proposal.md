Here is the raw text conversion for the remaining files you attached.

---

### File: chapter 1 draft v1.pdf

**Theoretical and Operational Foundations of the FlexiQueue System: A Comprehensive Review of Multi-Service Government Queue Management**

The modernization of public service delivery in the Philippines is fundamentally reshaped by the intersecting demands of the Mandanas-Garcia ruling and the digital transformation imperatives accelerated by the global health crisis. In this landscape, the Municipal Social Welfare and Development Office (MSWDO) operates as a critical node for social protection, yet it remains burdened by manual, paper-based workflows that struggle to accommodate the complexity of diverse assistance programs. The FlexiQueue system, a universal queue management platform proposed for MSWDO Tagudin, addresses these systemic inefficiencies through a table-based, offline-first architecture. This report analyzes the theoretical underpinnings and operational contexts of such a system by synthesizing fifteen distinct literary sources across international, national, and local levels.

**Global Perspectives on Queue Management and Server Behavior**

International literature provides the mathematical and behavioral framework necessary to understand the impact of queue configuration on institutional efficiency. Research in this domain transcends simple arrival-and-service models, delving into the psychological and operational nuances of how servers respond to different structural environments.

**Behavioral Queuing and the Dedicated Service Model**
A critical finding in global queuing research is the influence of queue configuration on human service speed and quality. A natural experiment in a supermarket setting demonstrated that servers in dedicated queues—where each server is responsible for a specific visible line—processed customers approximately 10.7% faster than those in shared or pooled queues. This discrepancy is attributed to two primary psychological mechanisms: the social loafing effect and the customer ownership effect. In pooled systems, servers may experience reduced accountability, assuming others will pick up the slack, whereas dedicated lines foster a sense of responsibility and an increased awareness of the remaining workload. This behavioral insight validates the "table-based" design of the FlexiQueue system. By organizing services around physical stations (Service Tables) rather than abstract, unified lines, the system effectively replicates the dedicated queue model. This structure is likely to mitigate social loafing among MSWDO staff by making individual table workloads visible and accountable. Furthermore, global research suggests that while pooled queues are theoretically more efficient at reducing bottlenecks, the human element often makes dedicated configurations more effective in practice.

**Mathematical Frameworks for Multi-Service Environments**
The optimization of queueing systems often relies on classical models, specifically the M/M/1 and M/M/c frameworks. These models define systems where arrivals follow a Poisson distribution with rate , and service times are exponentially distributed with rate . In a single-server system (), the probability of having  customers in the system () and the average wait time () are foundational metrics for evaluating service performance. For stable operations, the utilization factor  must remain below 1. In multi-server environments (), such as an MSWDO office running multiple assistance programs simultaneously, the complexity increases. The probability that all servers are busy () determines the likelihood of a queue forming. International research indicates that "smart" queuing systems—those incorporating real-time monitoring and dynamic prioritization—can reduce wait times by up to 60% by balancing these mathematical variables in real-time.

**Global Market Trends and Technological Shifts**
The global queue management system (QMS) market is currently experiencing a shift toward cloud-based deployments and AI integration, with an estimated valuation of $811.5 million in 2024. Major providers focus on scalability and remote accessibility; however, a significant subset of the literature highlights the "expertise requirement" and the challenges of maintaining such systems in resource-constrained environments. While cloud solutions are becoming standard in North America and Europe, the need for "offline-first" capabilities remains a critical gap for developing nations where infrastructure is unstable.

**(Table: QMS Comparison)**
| QMS Type | Deployment | Primary Benefit | Operational Constraint |
| :--- | :--- | :--- | :--- |
| **Linear Queuing** | Physical/Local | Simplicity and order | Limited data and flexibility |
| **Virtual Queuing** | Cloud-based | Perceived wait reduction | Requires stable internet |
| **FlexiQueue (Proposed)** | Offline-first/SBC | Portability and Process Integrity | Requires localized hardware setup |
| **AI-Powered QMS** | Hybrid/Cloud | Predictive resource allocation | High cost and technical expertise |

**National Context: Digitalization Challenges in Philippine Social Welfare**

In the Philippines, the DSWD has led a "bold digitalization agenda," particularly in response to the pandemic's disruptions. However, the translation of these national goals into effective municipal-level operations faces significant hurdles, as documented in various national studies.

**Devolution and the Mandanas-Garcia Ruling**
The implementation of the Mandanas-Garcia ruling has radically increased the responsibilities of Local Government Units (LGUs), specifically mandating the full devolution of social welfare programs like the Assistance to Individuals in Crisis Situation (AICS) and the Social Pension for Indigent Senior Citizens (SocPen). This shift places immense pressure on MSWDOs to manage voluminous transactions with limited personnel. Research indicates that many MSWDOs still rely on "handwritten forms" and manual data entry in Excel, which lacks the interoperability needed to link program databases or verify beneficiary identities effectively. The FlexiQueue system addresses this by providing a unified platform that adapts to these devolved programs without requiring code changes. National literature suggests that the lack of standardized operational procedures is a primary bottleneck in social welfare delivery. By integrating a process configuration system that allows administrators to define required forms and verification steps (e.g., ID checks, interview completions) for each program, FlexiQueue provides the "standardization" that national researchers argue is currently missing.

**Domestic Technological Interventions**
Various Philippine LGUs have piloted queuing and information systems with varying degrees of success:

* **Cabanatuan City:** Developed a "Community Request Queue Management System" to reduce physical traffic and modernize barangay-level record retrieval.
* **Placer, Surigao del Norte:** Proposed an integrated information system using data mining to monitor cash assistance budgets and digitize sectoral records.
* **Makati City:** Implemented a "Virtual Queue Management System" to manage health services, though such systems often require high smartphone penetration.
* **Cagayan de Oro:** Developed a LAN-based Service Queue Management System to address "disorderly" queuing at city health offices.

Despite these efforts, national research highlights that many systems remain "siloed"—focused on a single department or a specific type of request. The FlexiQueue proposal's emphasis on "configurability for multi-service operations" represents a second-order advancement, moving from simple automation to a flexible institutional tool.

**Local Landscape: Ilocos Sur and the Tagudin Operational Reality**

The local context of Ilocos Sur, and Tagudin specifically, presents unique geographic and socio-economic challenges that necessitate a highly portable and resilient queuing solution.

**Socio-Economic Realities and the Digital Divide**
Tagudin is home to over 41,000 residents, including vulnerable indigenous groups like the Bagos. Local research indicates that while administrative leadership in the LGU is strong, the "digital divide" remains a significant barrier. A study at the Ilocos Sur Polytechnic State College (ISPSC) Tagudin Campus revealed that nearly 75% of students come from families with monthly incomes below Php 10,000, which limits their ability to afford internet connectivity or personal digital devices. This socio-economic profile is critical for the design of FlexiQueue. A system that relies solely on mobile apps would exclude the most vulnerable beneficiaries. The proposed use of "Queue Informant Devices"—self-service kiosks where clients scan paper stubs to check their status—is a necessary local adaptation. It provides the benefits of digital transparency without requiring the user to own a smartphone or have mobile data.

**Institutional Readiness and Connectivity in Ilocos Sur**
Ilocos Sur has shown proactive engagement with Information Systems Strategic Planning (ISSP), as seen in the municipality of Nueva Era. Furthermore, the province has been a site for DICT's "Free Wi-Fi for All" and Starlink deployments in Geographically Isolated and Disadvantaged Areas (GIDA). However, the reliability of these connections is still subject to the province's frequent typhoons and mountainous terrain. The ISPSC itself has faced challenges in the "use and adoption" of its Human Resource Information System (HRIS), suggesting that technical solutions must be accompanied by significant training and a user-friendly design. FlexiQueue's architecture, which utilizes "single-board computers" and "portable wireless access points," allows for an offline-first deployment that is not dependent on the city's broader internet infrastructure during critical relief operations.

**(Table: Local Research Summary)**
| Local Source | Focus Area | Key Finding | Relevance to FlexiQueue |
| :--- | :--- | :--- | :--- |
| **ISSP Nueva Era** | Municipal IT Planning | Transition from conventional paradigms is vital for efficiency. | Supports the strategic goal of LGU modernization. |
| **Metro Vigan Telehealth** | Digital Health Access | Socio-economic factors influence digital adoption. | Justifies the need for non-smartphone-dependent informant devices. |
| **ISPSC HRIS Study** | Employee Performance | Adoption challenges persist despite system availability. | Highlights the importance of the FlexiQueue "Table-based" intuitive UI. |
| **ISPSC Tagudin (IS)** | Student Information | Manual processes lead to slow, inefficient service delivery. | Validates the need for automation in Tagudin-specific institutions. |
| **Tagudin IPRA Study** | Indigenous Governance | High LGU capability for program implementation, but information gaps exist. | Identifies the opportunity for better information dissemination via QMS. |

**5C Synthesis of Literature and System Components**

The following synthesis integrates international, national, and local findings using the 5C paragraph structure to establish the research context for the FlexiQueue system.

**Synthesis 1: Configurability and Multi-Service Dynamics**

* **Claim:** A configurable, table-based queuing architecture is superior to fixed-logic systems for government agencies managing diverse assistance programs with varying process flows.
* **Compare:** International literature on multi-service queuing optimization (e.g., in hospitals and petrol stations) suggests that total system performance, measured as "average sojourn time," improves by up to 6.9% when service types are optimally allocated to servers. While global systems often use complex algorithms like Particle Swarm Optimization to determine these flows, the FlexiQueue system provides a user-accessible configuration table that mirrors this logic without requiring specialized programming.
* **Contextualize:** In the specific environment of MSWDO Tagudin, programs like the 4Ps require rigid verification, while disaster relief requires rapid, high-volume distribution. National research confirms that MSWDOs struggle when "too rigid" systems force different programs into identical workflows.
* **Critique:** Existing systems, such as the one implemented in Cabanatuan, are often tailored for specific barangay requests and lack the architectural "fluidity" to pivot between a pension release and a social case study interview.
* **Conclude:** Therefore, FlexiQueue's "Universal" design—capable of reconfiguring "service tables" and "process checklists" on-the-fly—addresses the core operational mismatch identified in Philippine municipal governance.

**Synthesis 2: Offline-First Architecture for Institutional Resilience**

* **Claim:** Institutional effectiveness in the Philippines requires "offline-first" technology that functions independently of internet connectivity during emergencies or in remote locations.
* **Compare:** While the global trend favors cloud-based QMS for its "remote monitoring" and "analytics", international research into IoT frameworks for "weak signal" areas (warehouses, remote farms) proves that local microcontroller-based systems are more reliable for continuous service.
* **Contextualize:** Ilocos Sur's GIDA barangays frequently lose connectivity during the monsoon season, the very time when MSWDO relief operations are most active.
* **Critique:** National digitalization projects often fail because they assume a level of "broadband penetration" that is not yet a reality in the rural Philippines, leading to system failure at the "last mile" of service delivery.
* **Conclude:** By deploying FlexiQueue on compact, portable hardware with local network capabilities, MSWDO Tagudin can ensure service transparency and order regardless of the state of the national grid.

**Synthesis 3: Privacy, Anonymity, and Beneficiary Dignity**

* **Claim:** Protecting the privacy of social welfare beneficiaries through random alias generation is essential for maintaining institutional trust and human dignity.
* **Compare:** General queuing theory identifies "Priority Selection" (e.g., serving the most injured first) as a standard practice. However, in the Internet of Vehicles (IoV) and secure communication literature, the use of "pseudo-identities" is the gold standard for preventing identity profiling while maintaining an audit trail.
* **Contextualize:** In small Philippine municipalities, the calling of names in a crowded waiting room can expose a resident's indigent status or involvement in a VAWC case, creating social stigma.
* **Critique:** Most current manual systems rely on physical ticket numbers or verbal coordination, which are prone to favoritism ("palakasan") and offer no privacy protection.
* **Conclude:** FlexiQueue's "stub-driven" mechanism, where randomly generated aliases like "A1" track a client's progress, provides a sophisticated implementation of privacy-preserving technology in a social welfare context.

**Synthesis 4: Accountability and Audit Traceability**

* **Claim:** A comprehensive digital audit trail is necessary for COA compliance and to eliminate opportunities for corruption or favoritism in social service delivery.
* **Compare:** International business-oriented QMS emphasize "analytics" to measure "staff utilization" and "average wait times". In the Philippine public sector context, this is extended to "legal accountability," where every staff override and process completion must be immutable and verifiable.
* **Contextualize:** MSWDO workers in the Philippines are often under the direct influence of local executives, making them susceptible to political pressure in beneficiary selection.
* **Critique:** Manual ticketing systems (and even some basic digital ones) lack the "logging" capability to prove why a certain person was served out of order, leaving the agency vulnerable to complaints and audit findings.
* **Conclude:** FlexiQueue's "Flow Engine," which logs all client-service interactions and staff actions, transforms the queuing system from a simple line-manager into a robust tool for institutional integrity.

**Synthesis 5: User Empowerment and Information Transparency**

* **Claim:** Providing beneficiaries with real-time status visibility through "Informant Devices" reduces anxiety and improves the overall quality of public service.
* **Compare:** Research on the "WAITLESS" system in India shows that giving users "estimated wait times" and "queue status" improves time management and reduces crowding in physical halls.
* **Contextualize:** In Tagudin, senior citizens and PWDs often spend entire days waiting for social pension releases without knowing how many people are ahead of them or if they have submitted the correct documents.
* **Critique:** Existing school-based information systems in the region (like at ISPSC) focus on "administrative" tasks like billing and grading but often neglect the "front-end" transparency for the user, who remains "lagging" due to the digital divide.
* **Conclude:** FlexiQueue's self-service kiosk functionality empowers even the least tech-savvy resident to independently verify their status, promoting dignity and reducing the "waiting burden."

**Analysis of the Research Gap**
The systematic review of these fifteen sources reveals a significant research and implementation gap that the FlexiQueue system is uniquely positioned to fill.

1. **The Portability-Configurability Nexus:** While international literature discusses "configurable" systems and Philippine literature discusses "portable" relief operations, there is a lack of research on a single platform that integrates both. Most systems are either fixed (dedicated to one office) or highly complex (requiring cloud infrastructure). FlexiQueue's use of "table-based architecture" on "single-board computers" creates a new category of "Deployable Multi-Service QMS".
2. **Privacy in Local Governance:** National studies emphasize the "political susceptibility" of municipal workers. However, there is a dearth of practical tools that use "random aliases" to counteract favoritism while simultaneously providing the "audit trail" required for fiscal accountability.
3. **Low-Infrastructure Digital Inclusion:** Most modern QMS research assumes the user has a "mobile device". In the Ilocos Sur context, where the digital divide is documented, there is a clear need for "Informant Devices" that provide digital-level transparency to non-digital users.
4. **Process Integrity for Devolved Services:** Following the Mandanas ruling, LGUs need systems that can mirror "actual government service operations" (physical service tables) rather than abstract "virtual queues". The existing literature on LGU systems often focuses on "requests" (Cabanatuan) or "records" (Surigao) but does not address the "live flow" of a beneficiary moving through multiple verification steps in a single day.

**Synthesis of Operational Mechanisms and Future Outlook**
The FlexiQueue system represents a paradigm shift from traditional queue management to a comprehensive "service orchestration" platform. By leveraging the principles of behavioral queuing, offline IoT reliability, and privacy-preserving authentication, it provides a solution that is both technically sophisticated and locally appropriate for MSWDO Tagudin. The future of public service delivery in the Philippines depends on the ability of local institutions to remain "effective, accountable, and transparent" (SDG 16) even in the face of resource constraints and environmental instability. FlexiQueue's design—which balances "structured guidance" with "staff override capabilities"—acknowledges the reality that government service is not a factory line but a human-centered process that requires both order and empathy. As Ilocos Sur continues its path toward digital empowerment, systems like FlexiQueue will serve as the necessary infrastructure to bridge the gap between "manual tradition" and "digital excellence," ensuring that the most vulnerable citizens receive the services they deserve with dignity and speed.

**Works cited**
[List of 33 URLs/Sources included in original document]

---

### File: chapter 1 draft v2.pdf

**CHAPTER 1**
**THE PROBLEM AND ITS BACKGROUND**

**Background of the Study**

The administrative efficiency of public institutions is often measured by their ability to provide orderly, transparent, and timely services to the citizenry. However, many government offices in developing regions still struggle with the inherent chaos of manual queue management, where paper-based ticketing and verbal coordination lead to systemic breakdowns. These traditional methods frequently result in physical congestion, perceived favoritism, and significant staff burnout, creating a barrier between the government and the people it serves. Without a structured digital intervention, the manual-tradition bottleneck continues to compromise the dignity of beneficiaries and the operational integrity of the institution. There is an urgent need to transition toward automated systems that can handle high service volumes while maintaining flexibility for varying program requirements.

The global landscape of queue management is currently defined by a rapid shift toward automation and intelligent customer flow optimization. According to Orion Market Research (2025), the global Queue Management System (QMS) market was valued at $811.5 million in 2024 and is projected to reach over $1.5 billion by 2035, driven by the need to enhance user satisfaction across service sectors. Recent international studies highlight that smart QMS solutions in government and banking can achieve up to a 35% reduction in wait times and a 23% improvement in overall service efficiency. Beyond mere efficiency, global research into behavioral queuing suggests that "dedicated queues," where servers are responsible for specific visible lines, process customers approximately 10.7% faster than pooled systems because they mitigate the "social loafing" effect and increase individual accountability. Furthermore, the evolution of QMS now favors cloud-based platforms for real-time analytics, yet a critical gap remains for "offline-first" capabilities in resource-constrained environments where internet stability is not guaranteed. Modern global trends emphasize that while AI and cloud integration are the future, portability and localized resilience are essential for infrastructure-limited regions.

In the Philippine context, the demand for modernized public service delivery has reached a critical point following the implementation of the Mandanas-Garcia Ruling. This landmark Supreme Court decision expanded the fiscal base for Local Government Units (LGUs) but mandated the full devolution of complex social welfare programs, such as the Assistance to Individuals in Crisis Situation (AICS) and the Social Pension for Indigent Senior Citizens (SocPen), directly to the municipal level. To support this transition, the Department of Social Welfare and Development (DSWD) has launched a bold digitalization agenda, including the formation of an ICT Compliance Team to ensure that digital systems adhere to strict data privacy and cybersecurity standards. However, national researchers note that many digitalization projects fail at the "last mile" because they assume a level of broadband penetration that is not yet a reality in rural provinces. Furthermore, the DSWD and World Bank have collaborated on projects like BFIRST to strengthen social protection delivery, emphasizing that adaptation and efficiency are no longer luxuries but necessities. Despite these efforts, national studies confirm that many municipal offices still rely on "handwritten forms" and siloed data entry, highlighting a lack of standardized operational procedures across devolved services.

At the local level in Ilocos Sur, the digital divide remains a significant barrier to the adoption of standard cloud-based technologies. Research conducted at the Ilocos Sur Polytechnic State College (ISPSC) Tagudin Campus revealed that approximately 74.94% of students come from families with monthly incomes below Php 10,000, illustrating an economic reality where personal data plans and smartphones are often inaccessible. This socioeconomic profile necessitates the use of non-smartphone-dependent solutions, such as self-service "Queue Informant Devices," to ensure digital inclusion for the most vulnerable populations. While the Provincial Government of Ilocos Sur has proactively engaged in Information Systems Strategic Planning (ISSP) to modernize its LGUs, frequent typhoons and mountainous terrain often render internet-dependent systems useless during critical relief operations. Furthermore, local studies on Human Resource Information System (HRIS) adoption in the region indicate that technical systems must be accompanied by intuitive, user-friendly interfaces to overcome staff resistance and adoption challenges. Evidence from the Municipality of Tagudin specifically shows that while there is high LGU capability for program implementation, there are persistent information gaps that an automated, locally-hosted queuing system could fill.

The reviewed literature reveals a consistent pattern across global, national, and local contexts: while queue management technology is advancing, there is a distinct mismatch between cloud-centric global solutions and the offline-first, portable needs of devolved Philippine social services. International trends demonstrate the efficiency of dedicated table-based queuing, while national policies mandate a level of accountability that manual systems cannot provide. Local investigations in Ilocos Sur confirm that a significant digital divide and infrastructure vulnerability prevent the adoption of standard internet-dependent systems. This gap between the available high-end technology and the actual implementation realities of the MSWDO provides the foundation for the present study.

The reviewed literature provided critical insights into behavioral server speed, the administrative pressures of the Mandanas ruling, and the specific socioeconomic constraints of the Tagudin locale. These studies informed the researchers' understanding of the essential need for a system that is both technically robust and operationally flexible. The researchers selected this topic due to observable manual bottlenecks at the MSWDO of Tagudin, which result in long wait times and potential privacy breaches for beneficiaries. Addressing this problem would benefit both the administrative staff and the indigent citizens who rely on social protection services. Efficient, offline-resilient queue management is essential for maintaining institutional trust and process integrity, making this research both timely and necessary. Given the identified gap between existing manual practices and the technological requirements of full devolution, the development of a context-specific, universal queue management system becomes imperative.

**Conceptual Framework of the Study**

The study employs the Context, Input, Process, Product (CIPP) Evaluation Model as its conceptual framework. This model is selected because it provides a comprehensive, systematic approach to evaluating the FlexiQueue system not just as a piece of software, but as a socio-technical intervention designed to solve institutional problems. The framework addresses the environmental needs that justify the development, the resources planned, the implementation steps, and the final evaluated outcomes of the system.

The **context** component formally defines the needs assessment of the Municipal Social Welfare and Development Office (MSWDO) in Tagudin, Ilocos Sur. This includes the institutional pressures brought by the Mandanas-Garcia Ruling, the socio-economic challenges of the digital divide in the region, and the operational inefficiencies caused by the social loafing effect in existing pooled queues. The **input** component focuses on the design plan and the resources required to build the solution. This incorporates the theoretical underpinnings of behavioral queuing and the technical specifications of the Offline-First Architecture, the Table-Based configuration, and the selection of portable Single-Board Computers as the hardware backbone.

The **process** component documents the actual development and deployment of the FlexiQueue system. It covers the iterative stages of the System Development Life Cycle (SDLC) and the operational mechanics of the "Life of a Token" state machine. A critical element of this component is the Flow Engine, which is designed as a drag-and-drop administrative interface that allows the MSWDO head to visually orchestrate and connect different service stations. The **product** component determines the final effectiveness and impact of the implemented system. This is where the study evaluates the achievement of beneficiary privacy via random alias generation, the reduction in sojourn time, and the efficacy of the system in enforcing process integrity and providing an immutable audit trail for COA compliance.

The framework demonstrates a logical flow where the situational context dictates the system's design inputs, which in turn guide the iterative development process, ultimately resulting in a product that addresses the original institutional needs. Each component is interdependent; an accurate context assessment ensures relevant inputs, while a rigorous development process ensures a high-quality product that solves the identified queuing problems. This model aligns with the study's objectives by providing a holistic view of the system's lifecycle from requirements gathering to final evaluation.

[Figure 1. Conceptual Framework of the Study]

**Objectives of the Study**

The general objective of this project is to develop FlexiQueue: A Universal Queue Management System for Multi-Service Government Operations, which aims to provide a configurable, offline-first digital solution for optimizing beneficiary flow. This system will enhance MSWDO operations by automating service orchestration, improving beneficiary privacy through random alias generation, and ensuring institutional accountability through immutable audit trails.

Specifically, this study aimed:

1. To identify the existing manual queuing processes, operational bottlenecks, and program-specific requirements at the MSWDO of Tagudin;
2. To develop a portable, table-based queue management system featuring an administrative drag-and-drop flow engine and an offline-first architecture;
3. To evaluate the usability and acceptability of the proposed system using the System Usability Scale (SUS) and expert assessment.

**Scope and Limitation of the Study**

The study focuses on the design, development, and evaluation of the FlexiQueue system for the Municipal Social Welfare and Development Office (MSWDO) located in Tagudin, Ilocos Sur, Philippines, during the timeframe of Academic Year 2025-2026. The system accommodates three primary user types: administrators who use the drag-and-drop interface to configure program flows, staff members who manage specific service tables and scan tokens, and beneficiaries who utilize the informant displays to track their queue status. The system includes core modules for program configuration, real-time station management, a visual flow engine, and an automated audit logger for COA compliance. Principal variables processed include beneficiary aliases, program identifiers, service station timestamps, and process completion status. This locale and timeframe were selected to address the urgent operational pressures of full devolution in Tagudin while remaining manageable within the capstone project cycle.

The study is bound by several practical and technical limitations that define its boundaries. The system does not include real-time integration with national DSWD databases, such as Listahanan 3.0, due to the lack of available public APIs and security clearances; it functions strictly as a stand-alone local management tool. Access is limited to devices connected to the local Wi-Fi hotspot, as the system is designed to be "offline-first" to ensure resilience during disasters. The system does not process financial disbursements or biometrics; identity verification remains a manual process performed by staff checking physical IDs against the token holder. Furthermore, the system's operation is dependent on the availability of a local power source, such as a generator or battery, and does not solve electrical outages without external hardware. These limitations allow the researchers to focus on the core problem of service orchestration and queue management within a resource-constrained municipal environment.

**Importance of the Study**

The study provides significant benefits to various stakeholders within the municipal governance ecosystem.

* **The Community** benefits from a more organized and dignified public service experience, as the system reduces the chaos and long wait times typically associated with social protection payouts.
* **The MSWDO** gains a powerful administrative tool that enables operational autonomy, allowing the department head to rapidly reconfigure service flows for different programs without needing technical support.
* **The Respondents**, including the administrative staff and social workers, directly experience reduced workload stress through the elimination of manual ticketing and the mitigation of social loafing via clear table assignments.
* **The Students** of information technology gain a practical demonstration of how "offline-first" IoT and behavioral science can be applied to solve real-world problems in the Philippine government.
* **The Researchers** develop specialized skills in full-stack development using Laravel and Svelte, project management, and the implementation of real-time WebSocket communication in a local network setting.
* **Future Researchers** gain a reference framework for building deployable, multi-service systems in other LGU departments, such as Rural Health Units or Treasury Offices, and can build upon this study to explore native mobile integration or advanced predictive load balancing.

Beyond these immediate beneficiaries, the study contributes new knowledge to the field of IT in public administration by demonstrating the feasibility of a "Modular Monolith" architecture for deployable government operations. It adds to the growing body of literature on "service orchestration" in resource-limited settings, proving that high-level compliance features like audit trails and privacy-preserving aliases can be implemented on low-cost, portable hardware.

**Definition of Terms**

To ensure clarity and precision, the following terms are operationally defined within the context of this study:

* **Devolution:** The transfer of responsibility and power from national agencies like the DSWD to local government units, specifically regarding the implementation of social welfare programs.
* **Flow Engine:** The administrative module of the system that uses a drag-and-drop interface to allow users to visually define and connect the sequence of steps a beneficiary must follow for a specific program.
* **Offline-First Architecture:** A design philosophy where local functionality is the default experience, allowing the system to operate on a local server and Wi-Fi hotspot without an internet connection.
* **Random Alias Generation:** The process of assigning a non-identifying code (e.g., "A1") to a beneficiary's token to protect their identity and dignity in a public waiting area.
* **Single-Board Computer (SBC):** A compact computer, such as a Raspberry Pi or Mini-PC, used in this study as the portable local server for field deployments.
* **Social Loafing:** A behavioral phenomenon where individuals in a group (pooled queue) exert less effort than when working individually (dedicated tables), which the system aims to mitigate.
* **Sojourn Time:** The total time a beneficiary spends in the system, from the initial triage scan until the completion of the final service step.
* **Token Binding:** The digital association of a physical QR card with a specific beneficiary's profile and requested program for the duration of a service session.

**Works cited**
[List of 11 Citations included in original document]

---

### File: very first title proposal.pdf

**TITLE PROPOSAL**

**Researcher:** SARMIENTO, DAVID DATU N.
**Section:** 3A
**Proposed Research Title:** FlexiQueue: A Universal Queue Management System for Multi-Service Government Operations

**Description:**
FlexiQueue is a configurable queue management system designed to address the operational challenges faced by the Municipal Social Welfare and Development Office (MSWDO) and similar government agencies that operate multiple assistance programs with varying service requirements and process flows. Currently, MSWDO offices struggle to manage different programs such as cash assistance distributions, Pantawid Pamilya beneficiary verification, disaster relief operations, and social pension releases, each requiring different documents, verification steps, and service flows. Existing queue systems are either too rigid, forcing all programs into identical workflows, or entirely manual, relying on physical number tickets and verbal coordination that break down under high volume and create opportunities for confusion and favoritism.

FlexiQueue provides MSWDO with a single platform that can be configured according to specific program requirements without requiring code changes or external technical support. The system uses a table-based architecture that mirrors actual government service operations, where services are organized around physical service stations rather than abstract queue lines. This design allows administrators to create and modify programs rapidly, enabling adaptation to new policies, emergency response situations, or seasonal program variations. The system is designed with portability in mind, capable of being deployed on compact hardware such as single-board computers paired with portable wireless access points, making it suitable for both permanent office installations and temporary distribution sites in remote barangays or emergency relief operations where full infrastructure may not be available.

The system uses a stub-driven approach where clients receive privacy-protecting queue stubs with randomly generated aliases like "A1" or "B1" upon arrival. These stubs are pre-printed and exist independently of any specific program, allowing reuse across different MSWDO initiatives. When a client arrives, a routing clerk scans the stub and registers it to the active program, automatically creating the required process checklist based on program configuration. The stub then tracks the client's progress through multiple service tables and process types until all requirements are completed. When launching a new program, the MSWDO administrator configures which processes are required (such as consent forms, personal data sheets, interviews, ID verification, or cash claim forms), sets up service tables with appropriate staff assignments and capacity limits, and establishes flow rules that guide clients through required steps while allowing staff to override or adapt the flow based on real-time conditions.

The system supports complex scenarios where a single program deployment can have Table 1 with one staff member assisting two clients simultaneously on forms, Table 2 with two staff members conducting interviews, and Table 3 with three staff members processing claims for six clients at once. Some clients may only need verification and release, while others require additional steps, and the system adapts without requiring modifications. Three device types support operations: Queue Devices at each table display currently served stubs with audio-visual calling, implementable as staff smartphones or dedicated displays; Scanner Devices allow staff to scan stub barcodes using smartphone cameras to view status, assign clients to tables, and mark processes complete; Queue Informant Devices function as self-service kiosks where clients independently scan stubs to view their current table assignment, pending processes, completion status, and estimated position without staff assistance.

Flow configuration balances structured guidance with operational flexibility. Administrators define flow rules specifying process sequences and dependencies, but staff can override configured flows when necessary, with all overrides logged for accountability. The system operates fully offline using local network connectivity, ensuring reliability in remote locations without internet. All client movements, process completions, staff actions, and flow overrides are logged comprehensively for COA compliance and operational analysis. When program end dates are reached, all stubs registered to that program automatically expire, preventing reuse and maintaining data integrity across program cycles. By providing MSWDO with a single, portable platform that adapts to diverse program requirements, FlexiQueue enables more responsive, transparent, and efficient service delivery to Filipino beneficiaries who depend on government assistance, whether in the main office or remote distribution sites.

**Objectives:**

* To develop a table-based queue management platform that allows MSWDO administrators to configure service delivery for multiple assistance programs without requiring system code changes or external technical support.
* To design a portable, offline-first system architecture capable of deployment on compact hardware suitable for both permanent office installations and temporary distribution sites in remote locations.
* To design a process configuration system that allows administrators to define required forms, verification steps, and service flows specific to each MSWDO program while maintaining a reusable master catalog of process types.
* To implement a stub-driven tracking mechanism that protects client privacy through random alias generation while maintaining full audit traceability of all service transactions and client movements across program lifecycle.
* To create a flow engine that balances structured process guidance with staff override capabilities, allowing adherence to standard procedures while accommodating exceptions and special cases that commonly occur in government service delivery.
* To establish device types for different operational roles including table queue devices for client calling, scanner devices for routing and process tracking, and informant devices for self-service status checking, with support for both simple smartphone implementations and optional IoT display enhancements.
* To develop a comprehensive audit trail system that logs all client-service interactions, staff actions, process completions, and flow overrides for accountability, COA compliance verification, and operational analysis.

**SDG (Sustainable Development Goal) Alignment:**
FlexiQueue primarily aligns with Sustainable Development Goal 16: Peace, Justice, and Strong Institutions, particularly Target 16.6 which emphasizes developing effective, accountable, and transparent institutions at all levels, and Target 16.7 which focuses on ensuring responsive, inclusive, participatory, and representative decision-making at all levels. FlexiQueue directly supports these targets by addressing systemic inefficiencies in MSWDO service delivery that disproportionately affect vulnerable populations who depend on government assistance programs for survival and livelihood support.

In the Philippine context, MSWDO serves millions of beneficiaries annually through programs like Pantawid Pamilyang Pilipino Program (4Ps), Assistance to Individuals in Crisis Situation (AICS), Social Pension for Indigent Senior Citizens, and disaster response operations. Long wait times, unclear queuing processes, lack of real-time status visibility, and inconsistent service flows create significant barriers, particularly for elderly beneficiaries, persons with disabilities, pregnant women, and those with limited literacy who struggle to navigate complex government procedures. The portability of FlexiQueue strengthens institutional effectiveness by enabling MSWDO to bring services directly to remote barangays or emergency distribution sites, ensuring that even the most geographically isolated beneficiaries receive organized, transparent service delivery.

FlexiQueue's privacy-protecting stub system with random aliases ensures that beneficiaries are served based on objective arrival time and process requirements rather than personal recognition or staff favoritism, directly supporting institutional accountability. The queue informant devices empower beneficiaries to independently verify their status and remaining requirements without staff assistance, reducing anxiety and promoting dignity in the service experience. This transparency mechanism supports Target 16.6 by making service delivery visible and verifiable.

The system's configurability supports institutional effectiveness by allowing MSWDO offices to adapt service delivery to actual operational conditions and program requirements. When MSWDO launches new assistance programs or modifies eligibility requirements, offices can independently configure processes, define flow rules, and assign personnel without requiring system modification or external technical support. This operational autonomy strengthens institutional capacity and responsiveness to changing beneficiary needs and policy directives. FlexiQueue's comprehensive audit logging creates transparent records of every client movement, process completion, staff action, and flow override decision. These immutable logs can be reviewed by supervisors, Commission on Audit (COA) auditors, or oversight bodies investigating complaints, helping prevent corruption opportunities, supporting evidence-based management decisions, and building public trust in government service delivery.

Secondary alignment exists with SDG 10: Reduced Inequalities, as the system specifically accommodates varying levels of beneficiary capabilities. Queue informant devices provide accessible status checking that reduces reliance on verbal communication, benefiting those with hearing difficulties or language barriers. The random alias system eliminates potential discrimination based on recognizable names that might indicate ethnicity, religious affiliation, or social status, ensuring equitable treatment regardless of background.

The system also supports SDG 9: Industry, Innovation, and Infrastructure by demonstrating how appropriate technology can strengthen public service infrastructure in resource-constrained environments. The offline-first architecture with scalable display integration, combined with portable deployment capability on compact hardware, ensures that even offices in remote barangays with unreliable electricity or internet connectivity can implement modern queue management. The use of readily available devices like smartphones reduces hardware costs and technical barriers to adoption. Offices can start with simple smartphone-based deployments and progressively enhance their setup as resources become available, demonstrating a practical path for digital transformation in Philippine government social welfare operations.

Overall, FlexiQueue strengthens MSWDO's institutional effectiveness, promotes equitable service access regardless of location, ensures operational transparency, and empowers both government staff and beneficiaries through technology design that respects the realities of Philippine government operations and the dignity of vulnerable populations seeking assistance.

**Client/Recipient Agency/Office:** Municipal Social Welfare and Development Office (MSWDO) - LGU Tagudin
**Contact Person:** Laarni L. Acosta
**Rank:** Department Head

**Prepared by:**
DAVID DATU N. SARMIENTO
Researcher

**Approved:**
JOY G. BEA, DIT
Research Method Instructor

---

### File: letter to client.pdf

**Republic of the Philippines**
**ILOCOS SUR POLYTECHNIC STATE COLLEGE**
**College of Arts and Sciences**

January 30, 2026

**HON. EVANGELINE VERZOSA**
Municipal Mayor
Local Government Unit of Tagudin
Brgy. Rizal, Tagudin, Ilocos Sur

Ma'am:

I am writing to formally request permission to conduct a research study at the Municipal Social Welfare and Development Office (MSWDO) of LGU Tagudin as a requirement for the course Research Methods in the Bachelor of Science in Information Technology (BSIT) program. The study is titled "FlexiQueue: A Universal Queue Management System for Multi-Service Government Operations," which aims to design and develop a configurable queue management system to improve MSWDO's efficiency in handling multiple assistance programs with varying service requirements and process flows. This research will involve gathering relevant data to assess existing queue management practices and identify areas for improvement through technological solutions.

I assure you that all data collected will be treated with the utmost confidentiality and will be used solely for academic purposes. No sensitive information will be disclosed publicly, and findings will be shared with your office upon request.

I would greatly appreciate your support and cooperation with this research. I look forward to your positive response.

Thank you for your time and consideration.

Respectfully yours,

**(Signed)**
DAVID DATU N. SARMIENTO
Researcher

**Noted by:**

JIM-MAR F. DELOS REYES, MIT
BSIT Program Head

DANIEL JUAN B. RAMIREZ, PhD.
Campus Director

JOY G. BEA, DIT
Officer-in-Charge, Office of the CAS Dean

**Approved:**

**(Signed)**
HON. EVANGELINE VERZOSA
Municipal Mayor

**[Side/Footer Information included in Document]**

* **VISION:** ISPSC 2030, A smart globally competitive and responsive state university.
* **MISSION:** ISPSC is committed to producing globally competent and virtuous human resources, generating advanced knowledge, and developing innovative technologies for the sustainable development of society.
* **CORE VALUES:** Innovativeness, Motivation, Professionalism, Excellence, Transparency and Accountability, Unity and Teamwork, Sustainability.
* **MOTTO:** Sapientia et Virtus in Posterum (Wisdom and Virtue for the Future)
* **DEVELOPMENT GOALS:** Optimize institutional quality and reputation; Nurture excellence in academics and services; Widen research, development, and Innovation programs; Advance responsive extension services and vibrant external linkages; Revitalize capacity for resource management and generation; Develop capability and competence of human resources; Sustain ethical standards.