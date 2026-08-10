# Interview guides — does the public case study earn enquiries?

Two tracks. **Do not merge them.** Prospects and existing clients answer
different questions, and asking a client to evaluate our marketing produces
politeness, not data.

Before any session, read the moderator rules at the bottom.

---

## Setup checklist

- [ ] Three case studies published and visible (`php artisan db:seed --class=PortfolioDemoSeeder`
      seeds sample ones for a pilot; **real work only for real participants**)
- [ ] One of them has *Show sales and orders on the website* unticked
- [ ] Participant on a phone for at least half of Track A
- [ ] Recording consent taken, out loud, at the top of the call
- [ ] Notetaker present, or transcription running

---

## Track A — Prospects (45 min)

Leads who enquired but didn't buy, plus businesses in the target market.
**Never say the site is ours until the wrap-up.**

### 1. Warm-up — 5 min

- Tell me about the business. What do you make, and who buys it?
- How do you get in front of new customers at the moment?

### 2. Context — 10 min

- Walk me through the last time you hired someone to make video.
- How did you choose them? What made you trust them enough to pay?
- What did you get back at the end? Did anyone show you numbers?
- *(if yes)* What did you do with those numbers? Did anyone else see them?

### 3. First contact — 5 min · **Q1, Q5**

Share the landing page. Say only: **"Spend a minute here. Think out loud."**
Then stop talking. Do not point at anything.

Watch and note, don't ask:
- Do they scroll as far as the Work section?
- Do they notice *Read the case study*, and do they click it unprompted?
- Where do they stop?

Then:
- In your own words, what does this company do?
- Who do you think they normally work with?

### 4. Deep dive — 15 min · **Q2**

Open one case study. Let them scroll it end to end, thinking aloud, before you
ask anything.

- What is this page trying to tell you?
- Which number here matters most to you?
- Is there anything here you don't believe?
- *(at the big views figure)* What does that make you think?
- *(at the comparison table)* Whose average is that, and does the comparison
  feel fair to you?
- If a competitor of yours had a page like this made about them, what would you do?

### 5. Reaction — 7 min · **Q3 from the buying side**

Show the same piece with business impact hidden.

- What's different about this one?
- If a studio were pitching you, which of these two would you rather receive?
- Does leaving the sales figures out make it weaker, or more believable?

### 6. Wrap-up — 3 min · **Q4**

- If you wanted to talk to this studio right now, what would you do?
  **Watch. Do not help.** Note every tap until they either reach the form or
  give up.
- Was there anything you expected to find and didn't?
- Anything I should have asked?

---

## Track B — Existing clients (30 min)

People whose work is, or could be, published. No prototype until section 3.

### 1. Warm-up — 5 min

- How did the project go, from your side?
- What did you end up using the films for?

### 2. Context — 8 min

- Did you measure what the video did for you? What did you look at?
- Who else in the business saw those numbers?
- Is that the sort of thing you'd normally share outside the company?

### 3. Deep dive — 12 min · **Q3**

Show their own case study, or the nearest equivalent.

- Is this a fair account of what we did for you?
- *(at the sales figures)* How do you feel about that being on a public website?
- Who would have to approve that before it went up?
- What would you want taken off? What's missing?

### 4. Wrap-up — 5 min

- If someone asked you to recommend a studio, would you send them this page?
- What would make you more comfortable sharing it?

---

## Moderator rules

1. **Silence is data.** After a prompt, count to ten before filling the gap.
2. **Never defend the design.** If they misread something, that is the finding.
   "Say more about that" — not "well, what that means is…".
3. **Don't ask them to predict.** "Would you enquire?" is worthless. "Show me
   what you'd do next" is evidence.
4. **Ask about the last time, not the usual time.** Specific memories are true;
   general habits are self-image.
5. **Track A never learns it's our site** until the wrap-up. Then tell them, and
   give them the chance to withdraw anything they said.
6. **One section nobody mentions is worth more than five compliments.** Note what
   drew no comment at all.

---

## Analysis

- **Comprehension (Q1)** — binary per participant: did they describe the
  business correctly after first contact? Report as *n of 6*, never a percentage.
- **Affinity mapping (Q1, Q2)** — one observation per sticky, grouped after all
  sessions, not during. Then list the sections nobody mentioned.
- **Q3 is not a vote.** One client refusing publication of their revenue settles
  the default for `show_business_impact`, whatever the others say.
- **Q4/Q5** — count taps to the enquiry form, and count how many reached a case
  study without being told it existed.
- **Impact/effort matrix** for the recommendations, so the output is an ordered
  list rather than a wish list.

## Cross-check against the live data

After the sessions, compare what people said with what the site recorded. The
enquiry inbox now shows **Came from** — Landing page, Portfolio grid or Case
study — plus whatever they wrote in *What made you get in touch?*.

Treat a disagreement between the two as the most interesting result in the
study, not as an error in either.
